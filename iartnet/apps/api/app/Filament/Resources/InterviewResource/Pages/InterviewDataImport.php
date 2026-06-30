<?php

declare(strict_types=1);

namespace App\Filament\Resources\InterviewResource\Pages;

use App\Filament\Resources\InterviewResource;
use App\Models\Institution;
use App\Services\Interview\InterviewMasterImportService;
use App\Support\IngestionPaths;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\Actions as SchemaActions;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\Features\SupportFileUploads\WithFileUploads;
use RuntimeException;

class InterviewDataImport extends Page implements HasForms
{
    use InteractsWithForms;
    use WithFileUploads;

    protected static string $resource = InterviewResource::class;

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $title = 'Data Import Interviews';

    protected string $view = 'filament.resources.interview-resource.pages.interview-data-import';

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
            'main_docx' => null,
            'captions_docx' => null,
            'images' => [],
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('stable_id')
                    ->label('Codice scheda (stable_id)')
                    ->required()
                    ->maxLength(2048)
                    ->helperText('Codice univoco della scheda Master.')
                    ->columnSpanFull(),
                Select::make('institution_id')
                    ->label('Institution')
                    ->options(fn () => Institution::query()->orderBy('name')->pluck('name', 'id'))
                    ->required()
                    ->searchable()
                    ->columnSpanFull(),
                FileUpload::make('main_docx')
                    ->label('Documento principale intervista (.docx)')
                    ->acceptedFileTypes([
                        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                    ])
                    ->disk('local')
                    ->directory('interview-import-tmp')
                    ->visibility('private')
                    ->required()
                    ->columnSpanFull(),
                FileUpload::make('captions_docx')
                    ->label('Didascalie (.docx)')
                    ->acceptedFileTypes([
                        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                    ])
                    ->disk('local')
                    ->directory('interview-import-tmp')
                    ->visibility('private')
                    ->required()
                    ->columnSpanFull(),
                FileUpload::make('images')
                    ->label('Immagini (.jpg / .jpeg)')
                    ->multiple()
                    ->acceptedFileTypes(['image/jpeg'])
                    ->disk('local')
                    ->directory('interview-import-tmp')
                    ->visibility('private')
                    ->reorderable()
                    ->columnSpanFull(),
                Placeholder::make('upload_status')
                    ->label('Step 1')
                    ->content(function (): string {
                        if ($this->preparedRunId === null) {
                            return 'Caricare i file e premere «Upload (Step 1)» per salvare sotto INGEST_FS_ROOT.';
                        }

                        return 'Upload completato. Cartella: interviews/'.$this->preparedRunId;
                    })
                    ->dehydrated(false)
                    ->columnSpanFull(),
                SchemaActions::make([
                    Action::make('upload_step')
                        ->label('Upload (Step 1)')
                        ->action('runUploadStep'),
                    Action::make('import_step')
                        ->label('Crea scheda Master (Step 2)')
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
            'data.main_docx' => ['required'],
            'data.captions_docx' => ['required'],
            'data.images' => ['nullable', 'array'],
            'data.images.*' => ['nullable'],
        ]);

        $state = $this->form->getState();
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
            $dir = IngestionPaths::interviewImportRunRoot($runId);
            if (! File::isDirectory($dir)) {
                File::makeDirectory($dir, 0755, true);
            }
            $imgDir = $dir.DIRECTORY_SEPARATOR.'images';
            if (! File::isDirectory($imgDir)) {
                File::makeDirectory($imgDir, 0755, true);
            }

            $this->copyUploadToPath($state['main_docx'], $dir.DIRECTORY_SEPARATOR.'main.docx');
            $this->copyUploadToPath($state['captions_docx'], $dir.DIRECTORY_SEPARATOR.'didascalie.docx');

            $images = $state['images'] ?? [];
            $i = 0;
            foreach ((array) $images as $img) {
                if ($img === null || $img === '') {
                    continue;
                }
                $i++;
                $this->copyUploadToPath($img, $imgDir.DIRECTORY_SEPARATOR.'img_'.sprintf('%03d', $i).'.jpg');
            }

            $this->preparedRunId = $runId;
            $this->importStableId = trim((string) ($state['stable_id'] ?? ''));
            $this->importInstitutionId = (string) ($state['institution_id'] ?? '');

            Notification::make()
                ->title('Step 1 completato')
                ->body('File salvati in INGEST_FS_ROOT/interviews/{runId}. Procedere con Step 2.')
                ->success()
                ->send();
        } catch (\Throwable $e) {
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
            $svc = app(InterviewMasterImportService::class);
            $result = $svc->importFromPreparedRun(
                $this->importStableId,
                $this->importInstitutionId,
                $this->preparedRunId
            );

            Notification::make()
                ->title('Scheda creata')
                ->body('Record Master e Interview creati.')
                ->success()
                ->send();

            $this->redirect(InterviewResource::getUrl('view', ['record' => $result['interview_id']]));
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
