<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\Institution;
use App\Models\MirrorInstance;
use App\Services\AddedFields\AddFieldsZipExtractor;
use App\Services\AddedFields\AddedFieldsImportService;
use App\Services\AddedFields\ExcelColumnValidator;
use App\Support\IngestionPaths;
use Illuminate\Support\Str;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Table;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Wizard;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportFileUploads\WithFileUploads;

class AddFieldsWizard extends Page implements HasForms
{
    use InteractsWithForms;
    use WithFileUploads;

    protected string $view = 'filament.pages.add-fields-wizard';

    protected static ?string $navigationLabel = 'Add Fields';

    public static function getNavigationGroup(): ?string
    {
        return 'Ingestion';
    }

    public static function getNavigationSort(): ?int
    {
        return 3;
    }

    protected static ?string $title = 'Import Added Fields';

    public static function getNavigationIcon(): ?string
    {
        return 'heroicon-o-plus-circle';
    }

    public ?array $data = [];

    public ?string $currentStep = 'target';

    public ?string $institutionId = null;

    public ?string $mirrorInstanceId = null;

    public ?string $institution_id = null;

    public ?string $mirror_instance_id = null;

    public $excel_file;

    public ?string $excelPath = null;

    /** @var array<int, string> Path degli Excel (uno per singolo file, più per zip). */
    public array $excelPaths = [];

    /** 'excel' = singolo file, 'zip' = zip scompattato con più Excel e/o immagini. */
    public ?string $uploadMode = null;

    /** Path radice estrazione zip (solo se uploadMode === 'zip'). */
    public ?string $extractionPath = null;

    /** Basename delle immagini nello zip (solo se uploadMode === 'zip'), per colonna "Nome file immagine". */
    public array $imageBasenames = [];

    public ?string $targetSchema = null;

    public ?array $validationResult = null;

    public ?array $importResult = null;

    public array $importedRecordIds = [];

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
        $this->excel_file = $this->data['excel_file'] ?? [];
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

                                    if ($state) {
                                        $instance = MirrorInstance::find($state);
                                        if ($instance) {
                                            $this->targetSchema = $instance->name;
                                        }
                                    }
                                }),
                        ]),
                    Wizard\Step::make('upload')
                        ->label('Upload Excel')
                        ->schema([
                            FileUpload::make('excel_file')
                                ->label('File Excel o ZIP')
                                ->acceptedFileTypes([
                                    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                                    'application/vnd.ms-excel',
                                    'application/zip',
                                    'application/x-zip-compressed',
                                ])
                                ->maxSize(102400) // 100 MB (per zip con più Excel e immagini)
                                ->required()
                                ->disk('local')
                                ->directory('data')
                                ->visibility('private')
                                ->live()
                                ->afterStateUpdated(function ($state) {
                                    Log::info('Add Fields: FileUpload afterStateUpdated called', [
                                        'state_type' => gettype($state),
                                        'state_value' => is_string($state) ? $state : (is_array($state) ? json_encode($state) : 'non-string/non-array'),
                                    ]);

                                    if ($state) {
                                        $tempFilePath = null;
                                        $originalState = $state;

                                        // Handle different state types (similar to IccdImportWizard)
                                        if (is_array($state)) {
                                            $tempFilePath = $state[0] ?? null;
                                            Log::info('Add Fields: State is array', [
                                                'array_count' => count($state),
                                                'first_element' => $tempFilePath,
                                            ]);
                                        } elseif (is_string($state)) {
                                            // Try as relative path first
                                            $tempFilePath = Storage::disk('local')->path($state);
                                            // If not found, try as absolute path
                                            if (! file_exists($tempFilePath)) {
                                                $tempFilePath = $state;
                                            }
                                            Log::info('Add Fields: State is string', [
                                                'original_state' => $state,
                                                'converted_path' => $tempFilePath,
                                            ]);
                                        } elseif (is_object($state) && method_exists($state, 'getRealPath')) {
                                            $tempFilePath = $state->getRealPath();
                                            Log::info('Add Fields: State is object with getRealPath', [
                                                'real_path' => $tempFilePath,
                                            ]);
                                        } elseif (is_object($state) && method_exists($state, 'path')) {
                                            $tempFilePath = $state->path();
                                            Log::info('Add Fields: State is object with path method', [
                                                'path' => $tempFilePath,
                                            ]);
                                        } else {
                                            Log::warning('Add Fields: State type not handled', [
                                                'state_type' => gettype($state),
                                            ]);
                                        }

                                        if ($tempFilePath && file_exists($tempFilePath)) {
                                            Log::info('Add Fields: Temporary file found', [
                                                'temp_file_path' => $tempFilePath,
                                                'file_exists' => file_exists($tempFilePath),
                                            ]);

                                            $this->validationResult = null;
                                            $this->dispatch('$refresh');

                                            try {
                                                $ext = strtolower(pathinfo($tempFilePath, PATHINFO_EXTENSION));
                                                if ($ext === 'zip') {
                                                    $this->handleZipUpload($tempFilePath);
                                                } else {
                                                    $this->handleExcelUpload($tempFilePath);
                                                }
                                            } catch (\Exception $e) {
                                                Log::error('Add Fields: Exception processing upload', [
                                                    'temp_path' => $tempFilePath,
                                                    'error' => $e->getMessage(),
                                                ]);
                                                $this->validationResult = [
                                                    'valid' => false,
                                                    'matched_template' => null,
                                                    'message' => 'Errore: '.$e->getMessage(),
                                                    'columns' => [],
                                                ];
                                            }
                                            $this->dispatch('$refresh');
                                        } else {
                                            Log::error('Add Fields: Temporary file not found or not readable', [
                                                'temp_file_path' => $tempFilePath,
                                                'file_exists' => $tempFilePath ? file_exists($tempFilePath) : false,
                                                'is_readable' => $tempFilePath ? is_readable($tempFilePath) : false,
                                            ]);
                                        }
                                    } else {
                                        Log::info('Add Fields: State is empty/null, skipping file processing');
                                    }
                                }),
                            Placeholder::make('validation_status')
                                ->label('Validation Status')
                                ->content(function () {
                                    Log::debug('Add Fields: Placeholder validation_status content called', [
                                        'excel_path' => $this->excelPath,
                                        'has_validation_result' => ! empty($this->validationResult),
                                        'validation_result' => $this->validationResult,
                                    ]);

                                    // Show loading state if file is uploaded but not yet validated
                                    if ($this->excelPath && ! $this->validationResult) {
                                        Log::debug('Add Fields: Showing loading state (file uploaded, validation in progress)');
                                        return new \Illuminate\Support\HtmlString(
                                            "<div class='p-4 bg-blue-50 border border-blue-200 rounded'>
                                                <p class='text-blue-800 font-semibold'>Validazione in corso...</p>
                                            </div>"
                                        );
                                    }

                                    if (! $this->validationResult) {
                                        Log::debug('Add Fields: Showing initial state (no file uploaded yet)');
                                        return new \Illuminate\Support\HtmlString(
                                            "<div class='p-4 bg-gray-50 border border-gray-200 rounded'>
                                                <p class='text-gray-800'>Carica un file Excel per iniziare la validazione.</p>
                                            </div>"
                                        );
                                    }

                                    $isValid = $this->validationResult['valid'] ?? false;
                                    $message = $this->validationResult['message'] ?? '';
                                    $template = $this->validationResult['matched_template'] ?? null;

                                    Log::debug('Add Fields: Showing validation result', [
                                        'is_valid' => $isValid,
                                        'message' => $message,
                                        'template' => $template,
                                    ]);

                                    if ($isValid) {
                                        return new \Illuminate\Support\HtmlString(
                                            "<div class='p-4 bg-green-50 border border-green-200 rounded'>
                                                <p class='text-green-800 font-semibold'>✓ {$message}</p>
                                                <p class='text-green-600 text-sm mt-2'>Template: {$template}</p>
                                            </div>"
                                        );
                                    }

                                    return new \Illuminate\Support\HtmlString(
                                        "<div class='p-4 bg-red-50 border border-red-200 rounded'>
                                            <p class='text-red-800 font-semibold'>✗ {$message}</p>
                                        </div>"
                                    );
                                })
                                ->dehydrated(false),
                        ]),
                    Wizard\Step::make('import')
                        ->label('Import Fields')
                        ->schema([
                            Placeholder::make('import_info')
                                ->label('Import')
                                ->content(function () {
                                    // Check if we have reached this step (file uploaded)
                                    if (empty($this->excelPath)) {
                                        // No file loaded yet, show neutral message
                                        return new \Illuminate\Support\HtmlString(
                                            "<div class='p-4 bg-gray-50 border border-gray-200 rounded'>
                                                <p class='text-gray-800'>Carica un file Excel nello Step 2 per procedere con l'importazione.</p>
                                            </div>"
                                        );
                                    }

                                    // Check if validation has been performed
                                    if (! $this->validationResult) {
                                        // Redirect back to upload step
                                        $this->currentStep = 'upload';
                                        Notification::make()
                                            ->title('File non validato')
                                            ->warning()
                                            ->body('Carica e valida un file Excel nello Step 2.')
                                            ->persistent()
                                            ->send();

                                        return new \Illuminate\Support\HtmlString(
                                            "<div class='p-4 bg-yellow-50 border border-yellow-200 rounded'>
                                                <p class='text-yellow-800 font-semibold'>File Excel non validato</p>
                                                <p class='text-yellow-600 text-sm mt-2'>Torna allo Step 2 per caricare e validare un file Excel.</p>
                                            </div>"
                                        );
                                    }

                                    // Check if validation failed
                                    if (! ($this->validationResult['valid'] ?? false)) {
                                        // Redirect back to upload step
                                        $this->currentStep = 'upload';
                                        Notification::make()
                                            ->title('File non valido')
                                            ->danger()
                                            ->body('Il file Excel deve essere valido per procedere all\'importazione.')
                                            ->persistent()
                                            ->send();

                                        return new \Illuminate\Support\HtmlString(
                                            "<div class='p-4 bg-red-50 border border-red-200 rounded'>
                                                <p class='text-red-800 font-semibold'>File Excel non valido</p>
                                                <p class='text-red-600 text-sm mt-2'>Il file Excel caricato non corrisponde a nessun template valido. Torna allo Step 2 per caricare un file valido.</p>
                                            </div>"
                                        );
                                    }

                                    $template = $this->validationResult['matched_template'] ?? 'Unknown';
                                    $schema = $this->targetSchema ?? 'N/A';

                                    return new \Illuminate\Support\HtmlString(
                                        "<div class='p-4 bg-blue-50 border border-blue-200 rounded mb-4'>
                                            <p class='text-blue-800 font-semibold'>Pronto per l'importazione</p>
                                            <p class='text-blue-600 text-sm mt-2'>Template: {$template}</p>
                                            <p class='text-blue-600 text-sm'>Schema: {$schema}</p>
                                        </div>"
                                    );
                                })
                                ->dehydrated(false),
                            Placeholder::make('import_button')
                                ->label('Import Data')
                                ->content(function () {
                                    // Show import button only if file is validated and valid
                                    //if (empty($this->excelPath) || ! $this->validationResult || ! ($this->validationResult['valid'] ?? false)) {
                                      //  return '';
                                    // }
                                    // Show button if import not completed yet
                                    if (empty($this->importResult)) {
                                        return new \Illuminate\Support\HtmlString(view('filament.components.add-fields-import-button')->render());
                                    }
                                    return '';
                                })
                                ->dehydrated(false),
                            Placeholder::make('import_results')
                                ->label('Import Results')
                                ->content(function () {
                                    if (! $this->importResult) {
                                        return '';
                                    }

                                    $imported = $this->importResult['imported'] ?? 0;
                                    $skipped = $this->importResult['skipped'] ?? 0;
                                    $errors = $this->importResult['errors'] ?? [];

                                    return new \Illuminate\Support\HtmlString(
                                        view('filament.components.add-fields-import-results', [
                                            'imported' => $imported,
                                            'skipped' => $skipped,
                                            'errors' => $errors,
                                        ])->render()
                                    );
                                })
                                ->dehydrated(false),
                        ]),
                    Wizard\Step::make('results')
                        ->label('Results')
                        ->schema([
                            Placeholder::make('imported_records')
                                ->label('Imported Records')
                                ->content(function () {
                                    // Check if we have reached this step (file uploaded and validated)
                                    if (empty($this->excelPath) || empty($this->validationResult)) {
                                        // No file loaded yet, show neutral message
                                        return new \Illuminate\Support\HtmlString(
                                            "<div class='p-4 bg-gray-50 border border-gray-200 rounded'>
                                                <p class='text-gray-800'>Completa i passaggi precedenti per visualizzare i risultati.</p>
                                            </div>"
                                        );
                                    }

                                    // Check if import has been completed
                                    if (! $this->importResult) {
                                        // Redirect back to import step
                                        $this->currentStep = 'import';
                                        Notification::make()
                                            ->title('Importazione non completata')
                                            ->warning()
                                            ->body('Completa l\'importazione nello Step 3.')
                                            ->persistent()
                                            ->send();

                                        return new \Illuminate\Support\HtmlString(
                                            "<div class='p-4 bg-yellow-50 border border-yellow-200 rounded'>
                                                <p class='text-yellow-800 font-semibold'>Importazione non completata</p>
                                                <p class='text-yellow-600 text-sm mt-2'>Torna allo Step 3 per eseguire l'importazione.</p>
                                            </div>"
                                        );
                                    }

                                    if (empty($this->importedRecordIds)) {
                                        return new \Illuminate\Support\HtmlString(
                                            "<div class='p-4 bg-gray-50 border border-gray-200 rounded'>
                                                <p class='text-gray-800'>Nessun record importato.</p>
                                            </div>"
                                        );
                                    }

                                    $service = new AddedFieldsImportService();
                                    $records = $service->getImportedRecords($this->targetSchema ?? '', $this->importedRecordIds);

                                    return new \Illuminate\Support\HtmlString(
                                        view('filament.components.add-fields-records-table', [
                                            'records' => $records,
                                        ])->render()
                                    );
                                })
                                ->dehydrated(false),
                        ]),
                ]),
            ]);
    }

    /**
     * Validate Excel file columns.
     */
    public function validateExcelFile(): void
    {
        Log::info('Add Fields: validateExcelFile called', [
            'excel_path' => $this->excelPath,
            'excel_path_exists' => $this->excelPath ? file_exists($this->excelPath) : false,
        ]);

        if (! $this->excelPath || ! file_exists($this->excelPath)) {
            Log::error('Add Fields: Excel file not found in validateExcelFile', [
                'excel_path' => $this->excelPath,
                'file_exists' => $this->excelPath ? file_exists($this->excelPath) : false,
            ]);

            $this->validationResult = [
                'valid' => false,
                'message' => 'File Excel non trovato',
            ];
            Log::info('Add Fields: Set validationResult to invalid, dispatching refresh', [
                'validation_result' => $this->validationResult,
            ]);
            $this->dispatch('$refresh');

            return;
        }

        try {
            $dataProvider = $this->mirror_instance_id
                ? (MirrorInstance::find($this->mirror_instance_id)?->data_provider ?? null)
                : null;

            Log::info('Add Fields: Starting Excel column validation', [
                'excel_path' => $this->excelPath,
                'data_provider' => $dataProvider,
            ]);

            $validator = new ExcelColumnValidator();
            $this->validationResult = $validator->validateColumns($this->excelPath, $dataProvider);

            Log::info('Add Fields: Validation completed', [
                'validation_result' => $this->validationResult,
                'is_valid' => $this->validationResult['valid'] ?? false,
                'matched_template' => $this->validationResult['matched_template'] ?? null,
                'message' => $this->validationResult['message'] ?? null,
            ]);

            // Force refresh to update the Placeholder component
            $this->dispatch('$refresh');
            Log::debug('Add Fields: Dispatched $refresh after validation');

            if ($this->validationResult['valid']) {
                Notification::make()
                    ->title('File Excel valido')
                    ->success()
                    ->send();
                Log::info('Add Fields: Sent success notification');
            } else {
                Notification::make()
                    ->title('File Excel non valido')
                    ->danger()
                    ->body($this->validationResult['message'])
                    ->send();
                Log::info('Add Fields: Sent error notification', [
                    'message' => $this->validationResult['message'],
                ]);
            }
        } catch (\Exception $e) {
            Log::error('Add Fields: Exception during validation', [
                'excel_path' => $this->excelPath,
                'error_message' => $e->getMessage(),
                'error_trace' => $e->getTraceAsString(),
            ]);

            $this->validationResult = [
                'valid' => false,
                'message' => 'Errore durante la validazione: '.$e->getMessage(),
            ];
            Log::info('Add Fields: Set validationResult to error, dispatching refresh', [
                'validation_result' => $this->validationResult,
            ]);
            $this->dispatch('$refresh');

            Notification::make()
                ->title('Errore validazione')
                ->danger()
                ->body($e->getMessage())
                ->send();
        }
    }

    /**
     * Gestione upload singolo file Excel: copia in data/ e valida.
     */
    private function handleExcelUpload(string $tempFilePath): void
    {
        $fileName = basename($tempFilePath);
        $uniqueFileName = time().'_'.uniqid().'_'.$fileName;
        $permanentPath = Storage::disk('local')->path('data/'.$uniqueFileName);
        $directory = dirname($permanentPath);
        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }
        if (! copy($tempFilePath, $permanentPath)) {
            $this->validationResult = ['valid' => false, 'message' => 'Impossibile salvare il file.'];
            return;
        }
        $this->excelPath = $permanentPath;
        $this->excelPaths = [$permanentPath];
        $this->uploadMode = 'excel';
        $this->extractionPath = null;
        $this->imageBasenames = [];
        Log::info('Add Fields: Excel saved', ['path' => $this->excelPath]);
        $this->validateExcelFile();
    }

    /**
     * Gestione upload file ZIP: estrazione sotto INGEST_FS_ROOT/addfields/{uuid}, validazione di ogni Excel.
     */
    private function handleZipUpload(string $tempFilePath): void
    {
        $runId = (string) Str::uuid();
        $extractionPath = IngestionPaths::root().DIRECTORY_SEPARATOR.'addfields'.DIRECTORY_SEPARATOR.$runId;
        if (! is_dir($extractionPath)) {
            mkdir($extractionPath, 0755, true);
        }

        $extractor = new AddFieldsZipExtractor();
        $result = $extractor->extract($tempFilePath, $extractionPath);

        $excelPaths = $result['excel_paths'] ?? [];
        $imageBasenames = $result['image_basenames'] ?? [];
        $warnings = $result['warnings'] ?? [];

        if (count($excelPaths) === 0) {
            $this->validationResult = [
                'valid' => false,
                'matched_template' => null,
                'message' => 'Il file ZIP caricato non contiene alcun file Excel valido.',
                'columns' => [],
            ];
            if (count($warnings) > 0) {
                Notification::make()->title('ZIP non valido')->warning()->body(implode(' ', $warnings))->send();
            }
            return;
        }

        $dataProvider = $this->mirror_instance_id
            ? (MirrorInstance::find($this->mirror_instance_id)?->data_provider ?? null)
            : null;
        $validator = new ExcelColumnValidator();
        $firstValidResult = null;
        $invalidMessage = null;

        foreach ($excelPaths as $path) {
            $vr = $validator->validateColumns($path, $dataProvider);
            if (! ($vr['valid'] ?? false)) {
                $invalidMessage = $vr['message'] ?? 'Uno o più file Excel nello ZIP non sono validi.';
                break;
            }
            if ($firstValidResult === null) {
                $firstValidResult = $vr;
            }
        }

        if ($invalidMessage !== null) {
            $this->validationResult = [
                'valid' => false,
                'matched_template' => null,
                'message' => $invalidMessage,
                'columns' => [],
            ];
            Notification::make()->title('ZIP non valido')->danger()->body($invalidMessage)->send();
            return;
        }

        $this->excelPath = $excelPaths[0];
        $this->excelPaths = $excelPaths;
        $this->uploadMode = 'zip';
        $this->extractionPath = $extractionPath;
        $this->imageBasenames = $imageBasenames;
        $this->validationResult = $firstValidResult;
        Log::info('Add Fields: ZIP processed', [
            'excel_count' => count($excelPaths),
            'image_count' => count($imageBasenames),
        ]);
        if (count($warnings) > 0) {
            Notification::make()->title('ZIP elaborato')->warning()->body(implode(' ', $warnings))->send();
        } else {
            Notification::make()->title('File ZIP valido')->success()->send();
        }
    }

    /**
     * Run import process.
     */
    public function runImport(): void
    {
        try {
            auth()->user()?->assertCanAccessInstitution($this->institutionId);
        } catch (\RuntimeException $e) {
            Notification::make()
                ->title('Errore')
                ->body($e->getMessage())
                ->danger()
                ->send();

            return;
        }

        \Illuminate\Support\Facades\Log::info('AddFieldsWizard: runImport called', [
            'excel_path' => $this->excelPath,
            'excel_path_empty' => empty($this->excelPath),
            'excel_path_is_null' => is_null($this->excelPath),
            'excel_path_exists' => $this->excelPath ? file_exists($this->excelPath) : false,
            'target_schema' => $this->targetSchema,
            'target_schema_empty' => empty($this->targetSchema),
            'target_schema_is_null' => is_null($this->targetSchema),
            'validation_result' => $this->validationResult,
            'validation_result_empty' => empty($this->validationResult),
            'validation_result_is_null' => is_null($this->validationResult),
            'validation_result_valid' => $this->validationResult['valid'] ?? null,
        ]);

        // Check each condition separately with detailed logging
        $missing = [];
        
        if (empty($this->excelPath)) {
            $missing[] = 'excelPath';
            \Illuminate\Support\Facades\Log::warning('AddFieldsWizard: excelPath is empty or null');
        } elseif (! file_exists($this->excelPath)) {
            $missing[] = 'excelPath (file not found)';
            \Illuminate\Support\Facades\Log::error('AddFieldsWizard: excelPath file does not exist', [
                'excel_path' => $this->excelPath,
            ]);
        }
        
        if (empty($this->targetSchema)) {
            $missing[] = 'targetSchema';
            \Illuminate\Support\Facades\Log::warning('AddFieldsWizard: targetSchema is empty or null');
        }
        
        if (empty($this->validationResult)) {
            $missing[] = 'validationResult';
            \Illuminate\Support\Facades\Log::warning('AddFieldsWizard: validationResult is empty or null');
        }

        if (! empty($missing)) {
            \Illuminate\Support\Facades\Log::error('AddFieldsWizard: Missing data for import', [
                'missing_fields' => $missing,
                'excel_path' => $this->excelPath,
                'target_schema' => $this->targetSchema,
                'validation_result' => $this->validationResult,
            ]);

            Notification::make()
                ->title('Errore')
                ->danger()
                ->body('Dati mancanti per l\'importazione: '.implode(', ', $missing))
                ->send();

            return;
        }

        if (! ($this->validationResult['valid'] ?? false)) {
            Notification::make()
                ->title('Errore')
                ->danger()
                ->body('Il file Excel non è valido')
                ->send();

            return;
        }

        $templateKey = $this->validationResult['matched_template'] ?? null;

        if (! $templateKey) {
            Notification::make()
                ->title('Errore')
                ->danger()
                ->body('Template non identificato')
                ->send();

            return;
        }

        try {
            $columnMap = $this->validationResult['column_map'] ?? null;
            $service = new AddedFieldsImportService();

            if ($this->uploadMode === 'zip' && count($this->excelPaths) > 0) {
                $aggregateImported = 0;
                $aggregateSkipped = 0;
                $aggregateErrors = [];
                $aggregateRecordIds = [];
                foreach ($this->excelPaths as $excelPath) {
                    \Illuminate\Support\Facades\Log::info('AddFieldsWizard: Importing Excel from zip', ['path' => $excelPath]);
                    $one = $service->importFields(
                        $excelPath,
                        $this->targetSchema,
                        $templateKey,
                        $columnMap,
                        $this->extractionPath,
                        $this->imageBasenames
                    );
                    $aggregateImported += (int) ($one['imported'] ?? 0);
                    $aggregateSkipped += (int) ($one['skipped'] ?? 0);
                    $aggregateErrors = array_merge($aggregateErrors, $one['errors'] ?? []);
                    $aggregateRecordIds = array_merge($aggregateRecordIds, $one['imported_record_ids'] ?? []);
                }
                $this->importResult = [
                    'imported' => $aggregateImported,
                    'skipped' => $aggregateSkipped,
                    'errors' => $aggregateErrors,
                    'imported_record_ids' => $aggregateRecordIds,
                ];
            } else {
                $this->importResult = $service->importFields(
                    $this->excelPath,
                    $this->targetSchema,
                    $templateKey,
                    $columnMap
                );
            }

            \Illuminate\Support\Facades\Log::info('AddFieldsWizard: Import completed', [
                'imported' => $this->importResult['imported'] ?? 0,
                'skipped' => $this->importResult['skipped'] ?? 0,
                'errors_count' => count($this->importResult['errors'] ?? []),
            ]);

            $this->importedRecordIds = $this->importResult['imported_record_ids'] ?? [];
            $this->dispatch('$refresh');

            Notification::make()
                ->title('Importazione completata')
                ->success()
                ->body("Importati: {$this->importResult['imported']}, Saltati: {$this->importResult['skipped']}")
                ->send();
        } catch (\Exception $e) {
            Notification::make()
                ->title('Errore importazione')
                ->danger()
                ->body($e->getMessage())
                ->send();

            $this->importResult = [
                'imported' => 0,
                'skipped' => 0,
                'errors' => [['reason' => $e->getMessage()]],
                'imported_record_ids' => [],
            ];
        }
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->canAccessFilament() ?? false;
    }
}
