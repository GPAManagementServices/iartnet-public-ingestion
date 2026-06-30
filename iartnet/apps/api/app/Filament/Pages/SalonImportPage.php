<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Resources\MasterDataCardResource;
use App\Models\Institution;
use App\Services\Salon\SalonMasterImportService;
use App\Services\Salon\SalonZipExtractor;
use App\Support\IngestionPaths;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions as SchemaActions;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\Features\SupportFileUploads\WithFileUploads;
use RuntimeException;

class SalonImportPage extends Page implements HasForms
{
    use InteractsWithForms;
    use WithFileUploads;

    protected static ?string $navigationLabel = 'Salon';

    protected static ?string $title = 'Import Salon';

    protected static ?string $slug = 'salon-import';

    public static function getNavigationGroup(): ?string
    {
        return 'Master';
    }

    public static function getNavigationSort(): ?int
    {
        return 11;
    }

    public static function getNavigationIcon(): ?string
    {
        return 'heroicon-o-photo';
    }

    protected string $view = 'filament.pages.salon-import-page';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public ?string $preparedRunId = null;

    public ?string $importStableId = null;

    public ?string $importInstitutionId = null;

    public function mount(): void
    {
        $this->form->fill([
            'stable_id' => '',
            'institution_id' => null,
            'excel_file' => null,
            'images_zip' => null,
        ]);
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->canAccessOperatoreSections() ?? false;
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Placeholder::make('step1_heading')
                    ->label('Step 1 — Upload dati')
                    ->content('Caricare file Excel (.xlsx), archivio immagini (.zip), codice scheda e istituzione. I file vengono salvati sotto INGEST_FS_ROOT/salons/{runId}.')
                    ->dehydrated(false)
                    ->columnSpanFull(),
                TextInput::make('stable_id')
                    ->label('Codice scheda SALON (stable_id)')
                    ->required()
                    ->maxLength(2048)
                    ->helperText('Codice univoco della scheda Master.')
                    ->columnSpanFull(),
                Select::make('institution_id')
                    ->label('Istituzione di riferimento')
                    ->options(fn () => Institution::query()->orderBy('name')->pluck('name', 'id'))
                    ->required()
                    ->searchable()
                    ->columnSpanFull(),
                FileUpload::make('excel_file')
                    ->label('File Excel (.xlsx)')
                    ->acceptedFileTypes([
                        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                    ])
                    ->disk('local')
                    ->directory('salon-import-tmp')
                    ->visibility('private')
                    ->required()
                    ->columnSpanFull(),
                FileUpload::make('images_zip')
                    ->label('Archivio immagini (.zip)')
                    ->acceptedFileTypes(['application/zip', 'application/x-zip-compressed'])
                    ->disk('local')
                    ->directory('salon-import-tmp')
                    ->visibility('private')
                    ->required()
                    ->columnSpanFull(),
                Placeholder::make('upload_status')
                    ->label('Stato upload')
                    ->content(function (): string {
                        if ($this->preparedRunId === null) {
                            return 'Dopo l\'upload, premere «Upload (Step 1)» per procedere allo Step 2.';
                        }

                        return 'Upload completato. Cartella: salons/'.$this->preparedRunId;
                    })
                    ->dehydrated(false)
                    ->columnSpanFull(),
                Placeholder::make('step2_heading')
                    ->label('Step 2 — Creazione scheda')
                    ->content('Legge Excel e immagini, crea la scheda Master SALON (record, metadata, web_resources, copia IIIF).')
                    ->dehydrated(false)
                    ->columnSpanFull(),
                SchemaActions::make([
                    Action::make('upload_step')
                        ->label('Upload (Step 1)')
                        ->action('runUploadStep'),
                    Action::make('import_step')
                        ->label('Crea scheda Master SALON (Step 2)')
                        ->color('success')
                        ->visible(fn (): bool => $this->preparedRunId !== null)
                        ->action('runImportStep'),
                ]),
            ])
            ->statePath('data');
    }

    public function runUploadStep(): void
    {
        $this->validate([
            'data.stable_id' => ['required', 'string', 'max:2048'],
            'data.institution_id' => ['required', 'uuid'],
            'data.excel_file' => ['required'],
            'data.images_zip' => ['required'],
        ]);

        $state = $this->form->getState();
        $stableId = trim((string) ($state['stable_id'] ?? ''));
        $institutionId = (string) ($state['institution_id'] ?? '');

        if (! Institution::query()->whereKey($institutionId)->exists()) {
            Notification::make()
                ->title('Istituzione non valida')
                ->body('L\'istituzione selezionata non esiste.')
                ->danger()
                ->send();

            return;
        }

        $exists = DB::connection('pgsql')->table('iartnet_master.records')
            ->where('stable_id', $stableId)
            ->exists();
        if ($exists) {
            Notification::make()
                ->title('Codice scheda già presente')
                ->body("Esiste già una scheda con stable_id '{$stableId}'.")
                ->danger()
                ->send();

            return;
        }

        $ingestionRoot = rtrim((string) config('ingestion.fs_root'), DIRECTORY_SEPARATOR);
        if ($ingestionRoot === '' || ! is_dir($ingestionRoot) || ! is_writable($ingestionRoot)) {
            Notification::make()
                ->title('INGEST_FS_ROOT non valido')
                ->body('Configurare INGEST_FS_ROOT su una directory esistente e scrivibile.')
                ->danger()
                ->send();

            return;
        }

        try {
            $runId = (string) Str::uuid();
            $dir = IngestionPaths::salonImportRunRoot($runId);
            if (! File::isDirectory($dir)) {
                File::makeDirectory($dir, 0755, true);
            }
            $imagesDir = $dir.DIRECTORY_SEPARATOR.'images';
            if (! File::isDirectory($imagesDir)) {
                File::makeDirectory($imagesDir, 0755, true);
            }

            $this->copyUploadToPath($state['excel_file'], $dir.DIRECTORY_SEPARATOR.'data.xlsx');

            $zipTmp = $dir.DIRECTORY_SEPARATOR.'upload.zip';
            $this->copyUploadToPath($state['images_zip'], $zipTmp);

            $extractor = new SalonZipExtractor;
            $result = $extractor->extract($zipTmp, $imagesDir);
            if ($result['image_paths'] === []) {
                throw new RuntimeException('Lo zip non contiene immagini .jpg/.jpeg/.png.');
            }
            @unlink($zipTmp);

            $this->preparedRunId = $runId;
            $this->importStableId = $stableId;
            $this->importInstitutionId = $institutionId;

            Notification::make()
                ->title('Step 1 completato')
                ->body('File salvati in INGEST_FS_ROOT/salons/{runId}. Procedere con Step 2.')
                ->success()
                ->send();
        } catch (\Throwable $e) {
            if (isset($dir) && is_dir($dir)) {
                File::deleteDirectory($dir);
            }
            Notification::make()
                ->title('Errore upload')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    public function runImportStep(): void
    {
        if ($this->preparedRunId === null || $this->importStableId === null || $this->importInstitutionId === null) {
            Notification::make()
                ->title('Step 1 mancante')
                ->body('Eseguire prima l\'upload (Step 1).')
                ->danger()
                ->send();

            return;
        }

        try {
            $svc = app(SalonMasterImportService::class);
            $result = $svc->importFromPreparedRun(
                $this->importStableId,
                $this->importInstitutionId,
                $this->preparedRunId
            );

            Notification::make()
                ->title('Scheda SALON creata')
                ->body('Record Master, metadata e web_resources creati.')
                ->success()
                ->send();

            $this->redirect(MasterDataCardResource::getUrl('view', ['record' => $result['stable_id']]));
        } catch (\Throwable $e) {
            Notification::make()
                ->title('Errore creazione scheda')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    private function copyUploadToPath(mixed $upload, string $destAbsolute): void
    {
        $src = $this->resolveUploadSourcePath($upload);
        if (! is_file($src)) {
            throw new RuntimeException('File upload non trovato sul server.');
        }
        $parent = dirname($destAbsolute);
        if (! File::isDirectory($parent)) {
            File::makeDirectory($parent, 0755, true);
        }
        if (! @copy($src, $destAbsolute)) {
            throw new RuntimeException("Copia fallita verso {$destAbsolute}");
        }

        if ($upload instanceof TemporaryUploadedFile) {
            @unlink($src);

            return;
        }
        if (is_string($upload) && $upload !== '') {
            Storage::disk('local')->delete($upload);
        }
    }

    private function resolveUploadSourcePath(mixed $upload): string
    {
        if ($upload instanceof TemporaryUploadedFile) {
            $path = $upload->getRealPath();
            if ($path === false || ! is_file($path)) {
                throw new RuntimeException('Upload temporaneo non leggibile.');
            }

            return $path;
        }
        if (is_string($upload) && $upload !== '') {
            return Storage::disk('local')->path($upload);
        }

        throw new RuntimeException('Formato file upload non supportato.');
    }
}
