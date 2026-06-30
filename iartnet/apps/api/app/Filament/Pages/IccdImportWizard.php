<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Data\Iccd\ImportRun;
use App\Models\Institution;
use App\Models\MirrorInstance;
use App\Services\Iccd\IccdImportService;
use App\Support\IngestionPaths;
use App\Support\IngestionPendingCleanup;
use App\Services\Iccd\IccdXsd10ValidatorService;
use App\Services\Iccd\JsonImportService;
use App\Services\Iccd\PackageFormatDetector;
use App\Services\Iccd\SbnImportService;
use App\Services\Iccd\ZipPackageInspector;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Wizard;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Features\SupportFileUploads\WithFileUploads;

class IccdImportWizard extends Page implements HasForms
{
    use InteractsWithForms;
    use WithFileUploads;

    protected string $view = 'filament.pages.iccd-import-wizard';

    protected static ?string $navigationLabel = 'Importa Data';

    public static function getNavigationGroup(): ?string
    {
        return 'Ingestion';
    }

    public static function getNavigationSort(): ?int
    {
        return 2;
    }

    protected static ?string $title = 'ICCD/SBN/Json Package Import';

    public static function getNavigationIcon(): ?string
    {
        return 'heroicon-o-arrow-up-tray';
    }

    public ?array $data = [];

    public ?string $currentStep = 'target';

    public ?string $institutionId = null;

    public ?string $mirrorInstanceId = null;

    // zipPath non deve essere serializzato da Livewire
    protected ?string $zipPath = null;

    // Proprietà pubbliche per Livewire entangle
    public ?string $institution_id = null;

    public ?string $mirror_instance_id = null;

    // FileUpload richiede una proprietà pubblica per Livewire
    public $zip_file;

    // ImportRun non può essere serializzato da Livewire, quindi lo rendiamo protected
    protected ?ImportRun $importRun = null;

    // Salva i dati necessari in proprietà pubbliche per Livewire
    public ?string $runId = null;

    public ?string $targetSchema = null;

    public ?array $packageInfo = null;

    public ?array $validationResults = null;

    public ?array $importResults = null;

    public bool $hasValidationErrors = false;

    public ?string $selectedRecordId = null;

    public ?string $detectedFormat = null;

    public function mount(): void
    {
        $this->form->fill();
        $this->currentStep = 'target';

        $scopedInstitutionId = auth()->user()?->getScopedInstitutionId();
        if ($scopedInstitutionId !== null) {
            $this->form->fill(['institution_id' => $scopedInstitutionId]);
            $this->institution_id = $scopedInstitutionId;
            $this->institutionId = $scopedInstitutionId;
        } else {
            $this->institution_id = $this->data['institution_id'] ?? null;
            if (isset($this->data['institution_id'])) {
                $this->institutionId = (string) $this->data['institution_id'];
            }
        }

        $this->mirror_instance_id = $this->data['mirror_instance_id'] ?? null;
        $this->zip_file = $this->data['zip_file'] ?? [];
    }



    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Wizard::make([
                    Wizard\Step::make('target')
                        ->label('Select Target')
                        ->schema([
                            Select::make('institution_id')
                                ->label('Institution')
                                ->options(fn (): array => auth()->user()?->allowedInstitutionOptions() ?? [])
                                ->default(fn (): ?string => auth()->user()?->getScopedInstitutionId())
                                ->required()
                                ->searchable()
                                ->live()
                                ->afterStateUpdated(function ($state, $set) {
                                    $this->institutionId = (string) $state;
                                    $this->institution_id = (string) $state;
                                    $set('mirror_instance_id', null);
                                    $this->mirrorInstanceId = null;
                                    $this->mirror_instance_id = null;
                                }),
                            Select::make('mirror_instance_id')
                                ->label('Mirror Schema')
                                ->options(function ($get) {
                                    $institutionId = $get('institution_id');
                                    if (empty($institutionId)) {
                                        return [];
                                    }
                                    return MirrorInstance::query()
                                        ->where('institution_id', (string) $institutionId)
                                        ->get()
                                        ->mapWithKeys(function ($instance) {
                                            return [
                                                (string) $instance->id => $instance->display_name.' ('.$instance->name.')',
                                            ];
                                        })
                                        ->toArray();
                                })
                                ->required()
                                ->searchable()
                                ->live()
                                ->afterStateUpdated(function ($state) {
                                    $this->mirrorInstanceId = $state ? (string) $state : null;
                                    $this->mirror_instance_id = $state ? (string) $state : null;
                                }),
                        ]),
                    Wizard\Step::make('upload')
                        ->label('Upload Package')
                        ->schema([
                            FileUpload::make('zip_file')
                                ->label('ICCD Package (ZIP)')
                                ->acceptedFileTypes(['application/zip', 'application/x-zip-compressed'])
                                ->maxSize(1048576) // 1 GB (1024 * 1024 KB)
                                ->required()
                                ->disk('local')
                                ->directory('iccd/uploads')
                                ->visibility('private')
                                ->live()
                                ->afterStateUpdated(function ($state) {
                                    if ($state) {
                                        $filePath = null;
                                        if (is_array($state)) {
                                            $filePath = $state[0] ?? null;
                                        } elseif (is_string($state)) {
                                            $filePath = $state;
                                        } elseif (is_object($state) && method_exists($state, 'getRealPath')) {
                                            $filePath = $state->getRealPath();
                                        } elseif (is_object($state) && method_exists($state, 'path')) {
                                            $filePath = $state->path();
                                        }
                                        if ($filePath) {
                                            $this->zipPath = $filePath;
                                            $this->processUpload();
                                        }
                                    }
                                }),
                            Placeholder::make('package_analysis')
                                ->label('Package Analysis')
                                ->content(function () {
                                    $info = $this->packageInfo;
                                    $totalFiles = $info && isset($info['files']) ? count($info['files']) : 0;
                                    $xmlFiles = $info && isset($info['xml_files']) ? count($info['xml_files']) : 0;
                                    $jsonFiles = $info && isset($info['json_files']) ? count($info['json_files']) : 0;
                                    $mediaFiles = $info && isset($info['media_files']) ? count($info['media_files']) : 0;
                                    $format = $info && isset($info['format']) ? $info['format'] : ($this->detectedFormat ?? 'Non rilevato');
                                    return new \Illuminate\Support\HtmlString(view('filament.components.package-analysis-content', [
                                        'totalFiles' => $totalFiles,
                                        'xmlFiles' => $xmlFiles,
                                        'jsonFiles' => $jsonFiles,
                                        'mediaFiles' => $mediaFiles,
                                        'format' => $format,
                                    ])->render());
                                })
                                ->dehydrated(false),
                        ]),
                    Wizard\Step::make('validate')
                        ->label('Validate & Import')
                        ->schema([
                            Placeholder::make('validation')
                                ->label('Validation')
                                ->content(function () {
                                    // Check if a package has been loaded first
                                    if (empty($this->packageInfo) || empty($this->runId)) {
                                        // No package loaded yet, show normal validation UI
                                        return new \Illuminate\Support\HtmlString(view('filament.components.validation-button')->render());
                                    }
                                    
                                    // Check if format is accepted (only after package is loaded)
                                    $format = $this->detectedFormat ?? ($this->packageInfo['format'] ?? null);
                                    
                                    if ($format === 'Formato non accettato') {
                                        // Redirect back to upload step and show notification
                                        $this->currentStep = 'upload';
                                        Notification::make()
                                            ->title('Formato non accettato')
                                            ->body('Il formato del pacchetto caricato non è supportato. Non è possibile procedere allo Step 3.')
                                            ->danger()
                                            ->persistent()
                                            ->send();
                                        
                                        return new \Illuminate\Support\HtmlString('<div class="p-4 bg-red-50 dark:bg-red-900/20 rounded-lg border border-red-200 dark:border-red-800">
                                            <p class="text-red-800 dark:text-red-200 font-semibold">Formato non accettato</p>
                                            <p class="text-red-600 dark:text-red-400 text-sm mt-2">Il formato del pacchetto caricato non è supportato. Torna allo Step 2 per caricare un pacchetto valido.</p>
                                        </div>');
                                    }
                                    
                                    // Show validation only for ICCD format
                                    if ($format !== 'ICCD') {
                                        return new \Illuminate\Support\HtmlString(view('filament.components.format-info', [
                                            'format' => $format ?? 'Non rilevato',
                                        ])->render());
                                    }

                                    // ICCD: validazione XSD nascosta (funzionalità resta nel codice per utilizzi futuri)
                                    // Non mostrare il pulsante "Run XSD Validation" né i risultati validazione
                                    return new \Illuminate\Support\HtmlString('');
                                })
                                ->dehydrated(false),
                            Placeholder::make('import_button')
                                ->label('')
                                ->content(function () {
                                    // Pulsante Importa i dati sempre visibile e operativo (ICCD senza validazione XSD, SBN/JSON come prima)
                                    return new \Illuminate\Support\HtmlString(view('filament.components.import-button')->render());
                                })
                                ->dehydrated(false),
                            Placeholder::make('import_result_box')
                                ->label('')
                                ->content(function () {
                                    // Box Info con risultato import (stesse informazioni della notifica) dopo termine importazione
                                    if (empty($this->importResults)) {
                                        return new \Illuminate\Support\HtmlString('');
                                    }
                                    $imported = $this->importResults['imported'] ?? 0;
                                    $skipped = $this->importResults['skipped'] ?? 0;
                                    $errors = $this->importResults['errors'] ?? 0;
                                    return new \Illuminate\Support\HtmlString(
                                        '<div class="mt-6 p-4 rounded-lg border border-gray-200 dark:border-gray-600 bg-gray-50 dark:bg-gray-900">'
                                        . '<h4 class="text-sm font-semibold text-gray-900 dark:text-gray-100 mb-2">Risultato importazione</h4>'
                                        . '<div class="text-sm text-gray-700 dark:text-gray-300 space-y-1">'
                                        . '<p><strong>Record inseriti:</strong> ' . (int) $imported . '</p>'
                                        . '<p><strong>Record saltati:</strong> ' . (int) $skipped . '</p>'
                                        . '<p><strong>Errori:</strong> ' . (int) $errors . '</p>'
                                        . '</div></div>'
                                    );
                                })
                                ->dehydrated(false),
                        ]),
                    Wizard\Step::make('preview')
                        ->label('Data Preview')
                        ->schema([
                            Placeholder::make('data_preview')
                                ->label('')
                                ->content(function () {
                                    return new \Illuminate\Support\HtmlString(view('filament.components.data-preview', [
                                        'targetSchema' => $this->targetSchema,
                                        'runId' => $this->runId,
                                        'selectedRecordId' => $this->selectedRecordId,
                                    ])->render());
                                })
                                ->dehydrated(false),
                        ]),
                ])
                    ->submitAction(null)
                    ->cancelAction(null),
            ]);
    }

    protected function processUpload(): void
    {
        // Aspetta un po' che il file sia completamente scritto
        $maxAttempts = 10;
        $attempt = 0;
        $fileExists = false;
        
        while ($attempt < $maxAttempts && ! $fileExists) {
            if ($this->zipPath && file_exists($this->zipPath)) {
                $fileExists = true;
                break;
            }
            usleep(500000); // 0.5 secondi
            $attempt++;
        }
        
        if (! $this->zipPath || ! $fileExists) {
            Notification::make()
                ->title('Upload failed')
                ->body('ZIP file not found at: '.($this->zipPath ?? 'null'))
                ->danger()
                ->send();

            return;
        }

        if (! $this->mirrorInstanceId) {
            Notification::make()
                ->title('Mirror schema required')
                ->body('Please select a mirror schema first')
                ->danger()
                ->send();

            return;
        }

        try {
            auth()->user()?->assertCanAccessInstitution($this->institutionId);

            $mirrorInstance = MirrorInstance::find($this->mirrorInstanceId);
            if (! $mirrorInstance) {
                throw new \RuntimeException('Mirror instance not found');
            }

            // Validate that user has access to this mirror instance
            if ($mirrorInstance->institution_id !== $this->institutionId) {
                throw new \RuntimeException('Mirror instance does not belong to selected institution');
            }

            // Create import run (paths from configurable ingestion root)
            $runId = (string) Str::uuid();
            $extractionPath = IngestionPaths::extractionPath($runId);
            $tmpPath = IngestionPaths::tmpPath($runId);
            $runStoragePath = IngestionPaths::runStoragePath($runId);

            // Create directories
            mkdir($extractionPath, 0755, true);
            mkdir($tmpPath, 0755, true);
            mkdir($runStoragePath, 0755, true);

            // Inspect and extract package (data_provider per SIGEC/MIDF: accetta ZIP senza IMMFTAN)
            $inspector = app(ZipPackageInspector::class);
            $packageData = $inspector->inspectAndExtract($this->zipPath, $extractionPath, $mirrorInstance->data_provider ?? null);

            // Create ImportRun DTO (protected, non serializzato da Livewire)
            $this->importRun = new ImportRun(
                runId: $runId,
                targetSchema: $mirrorInstance->name,
                extractionPath: $extractionPath,
                tmpPath: $tmpPath,
                runStoragePath: $runStoragePath,
                totalFiles: count($packageData['files']),
                xmlFiles: count($packageData['xml_files']),
                mediaFiles: count($packageData['media_files']),
                warnings: $packageData['warnings'],
            );

            // Salva i dati necessari in proprietà pubbliche per Livewire
            $this->runId = $runId;
            $this->targetSchema = $mirrorInstance->name;

            // Save package info (convert format enum to string for Livewire serialization)
            $packageDataForStorage = $packageData;
            if (isset($packageData['format']) && $packageData['format'] instanceof \App\Services\Iccd\PackageFormat) {
                $packageDataForStorage['format'] = $packageData['format']->value;
            }
            $this->packageInfo = $packageDataForStorage;
            $this->detectedFormat = $packageData['format']->value ?? null;
            file_put_contents(
                $this->importRun->getPackageJsonPath(),
                json_encode($packageDataForStorage, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
            );

            $this->currentStep = 'upload';

            // Registra per pulizia differita: se l'utente non prosegue entro 1 min, extraction+tmp vengono eliminati
            IngestionPendingCleanup::register($runId);

            Notification::make()
                ->title('Package extracted successfully')
                ->body('Package analyzed and ready. Click Next to proceed to validation.')
                ->success()
                ->send();

            // DO NOT auto-advance - user must click Next button
            // Force Livewire to refresh the view
            $this->dispatch('$refresh');

        } catch (\Exception $e) {
            Notification::make()
                ->title('Upload processing failed')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    public function runValidation(): void
    {
        // Reset timer: utente è proseguito, non eliminare extraction/tmp per timeout
        if ($this->runId) {
            IngestionPendingCleanup::clear($this->runId);
        }

        // Assicurati che currentStep sia 'validate' quando viene eseguita la validazione
        $this->currentStep = 'validate';

        if (! $this->runId || ! $this->importRun) {
            // Ricostruisci ImportRun se necessario
            $this->reconstructImportRun();
        }

        if (! $this->importRun) {
            Notification::make()
                ->title('No package loaded')
                ->danger()
                ->send();

            return;
        }

        try {
            $validator = app(IccdXsd10ValidatorService::class);
            $xmlFiles = $this->packageInfo['xml_files'] ?? [];

            $rawValidationResults = $validator->validateMultiple($xmlFiles);

            // Converti validationResults in array per Livewire IMMEDIATAMENTE
            $this->hasValidationErrors = false;
            $validationData = [];
            
            foreach ($rawValidationResults as $file => $issues) {
                $validationData[$file] = [];
                foreach ($issues as $issue) {
                    // Converti oggetto in array
                    $issueArray = is_object($issue) && method_exists($issue, 'toArray') 
                        ? $issue->toArray() 
                        : (array) $issue;
                    
                    $validationData[$file][] = $issueArray;
                    
                    // Check for errors usando l'array
                    if (($issueArray['severity'] ?? '') === 'error') {
                        $this->hasValidationErrors = true;
                    }
                }
            }

            // Salva i risultati come array per Livewire
            $this->validationResults = $validationData;

            // Aggiorna currentStep quando viene eseguita la validazione (siamo nello step 3)
            $this->currentStep = 'validate';

            file_put_contents(
                $this->importRun->getValidationJsonPath(),
                json_encode($validationData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
            );

            Notification::make()
                ->title('Validation completed')
                ->body($this->hasValidationErrors ? 'Validation found errors' : 'Validation passed')
                ->success()
                ->send();

        } catch (\Exception $e) {
            Notification::make()
                ->title('Validation failed')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    public function runImport(bool $proceedDespiteErrors = true): void
    {
        // Reset timer: utente è proseguito
        if ($this->runId) {
            IngestionPendingCleanup::clear($this->runId);
        }

        $format = $this->detectedFormat ?? ($this->packageInfo['format'] ?? null);

        if (! $this->runId) {
            Notification::make()
                ->title('No package loaded')
                ->danger()
                ->send();

            return;
        }

        // ICCD: validazione XSD non richiesta (pulsante nascosto nello Step 3); si passa array vuoto se non eseguita
        // Ricostruisci ImportRun se necessario
        if (! $this->importRun) {
            $this->reconstructImportRun();
        }

        try {
            // Route to appropriate import service based on format
            if ($format === 'ICCD') {
                $importService = app(IccdImportService::class);
                $this->importResults = $importService->runImport(
                    $this->importRun,
                    $this->validationResults ?? [],
                    $proceedDespiteErrors
                );
            } elseif ($format === 'SBN') {
                $importService = app(SbnImportService::class);
                $this->importResults = $importService->runImport($this->importRun);
            } elseif ($format === 'JSON') {
                $importService = app(JsonImportService::class);
                $this->importResults = $importService->runImport($this->importRun);
            } else {
                Notification::make()
                    ->title('Unsupported format')
                    ->body("Format '{$format}' is not supported for import.")
                    ->danger()
                    ->send();

                return;
            }

            // Resta nello Step 3 (Validate & Import) così la Box Info con il risultato è visibile
            // $this->currentStep = 'preview';

            // Al termine dell'import resta solo la cartella in root; elimina la cartella tmp
            IngestionPaths::deleteTmpOnly($this->runId);

            Notification::make()
                ->title('Import completed')
                ->body(
                    "Imported: {$this->importResults['imported']}"
                    .(($this->importResults['updated'] ?? 0) > 0 ? ", Updated: {$this->importResults['updated']}" : '')
                    .", Errors: {$this->importResults['errors']}"
                )
                ->success()
                ->send();

        } catch (\Exception $e) {
            Notification::make()
                ->title('Import failed')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    /**
     * Ricostruisce ImportRun dai dati salvati.
     */
    protected function reconstructImportRun(): void
    {
        if (! $this->runId || ! $this->targetSchema) {
            return;
        }

        $extractionPath = IngestionPaths::extractionPath($this->runId);
        $tmpPath = IngestionPaths::tmpPath($this->runId);
        $runStoragePath = IngestionPaths::runStoragePath($this->runId);

        // Load package info from JSON if not already loaded
        if (empty($this->packageInfo)) {
            $packageJsonPath = $runStoragePath.DIRECTORY_SEPARATOR.'package.json';
            if (file_exists($packageJsonPath)) {
                $packageData = json_decode(file_get_contents($packageJsonPath), true);
                if (is_array($packageData)) {
                    $this->packageInfo = $packageData;
                    $this->detectedFormat = $packageData['format'] ?? null;
                }
            }
        }

        // Ricostruisci ImportRun con i dati disponibili
        $this->importRun = new ImportRun(
            runId: $this->runId,
            targetSchema: $this->targetSchema,
            extractionPath: $extractionPath,
            tmpPath: $tmpPath,
            runStoragePath: $runStoragePath,
            totalFiles: $this->packageInfo['files'] ? count($this->packageInfo['files']) : 0,
            xmlFiles: $this->packageInfo['xml_files'] ? count($this->packageInfo['xml_files']) : 0,
            mediaFiles: $this->packageInfo['media_files'] ? count($this->packageInfo['media_files']) : 0,
            warnings: $this->packageInfo['warnings'] ?? [],
        );
    }

    /**
     * Check if format is accepted and can proceed to next step.
     */
    public function canProceedToValidate(): bool
    {
        $format = $this->detectedFormat ?? ($this->packageInfo['format'] ?? null);
        
        if ($format === 'Formato non accettato' || $format === null) {
            Notification::make()
                ->title('Formato non accettato')
                ->body('Il formato del pacchetto caricato non è supportato. Non è possibile procedere allo Step 3.')
                ->danger()
                ->persistent()
                ->send();
            
            return false;
        }
        
        return true;
    }

    /**
     * Proprietà da escludere dalla serializzazione Livewire.
     */
    protected function getNonSerializablePropertyNames(): array
    {
        return ['importRun', 'zipPath'];
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->canAccessFilament() ?? false;
    }
}
