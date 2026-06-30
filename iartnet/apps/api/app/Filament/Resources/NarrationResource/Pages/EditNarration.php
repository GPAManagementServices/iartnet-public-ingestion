<?php

declare(strict_types=1);

namespace App\Filament\Resources\NarrationResource\Pages;

use App\Filament\Resources\NarrationResource;
use App\Models\Narration;
use App\Support\IngestionPaths;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Contracts\View\View;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

class EditNarration extends EditRecord
{
    use \Livewire\Features\SupportFileUploads\WithFileUploads;

    protected static string $resource = NarrationResource::class;

    /** Add Media modal */
    public bool $showAddMediaModal = false;

    /** @var TemporaryUploadedFile|null */
    public $addMediaFile = null;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('storyEditor')
                ->label('Story Editor')
                ->icon('heroicon-o-pencil-square')
                ->color('info')
                ->action(function (): void {
                    $this->redirect(
                        NarrationResource::getUrl('story-editor', ['record' => $this->getRecord()]),
                        navigate: false,
                    );
                }),
            Actions\Action::make('addMedia')
                ->label('Add Media')
                ->icon('heroicon-o-photo')
                ->color('primary')
                ->action(function (): void {
                    $this->openAddMediaModal();
                }),
            Actions\Action::make('togglePublishState')
                ->label(function (): string {
                    return $this->narrationPublishState() === 'published' ? 'Unpublish' : 'Publish';
                })
                ->icon(function (): string {
                    return $this->narrationPublishState() === 'published'
                        ? 'heroicon-o-arrow-down-circle'
                        : 'heroicon-o-arrow-up-circle';
                })
                ->color(function (): string {
                    return $this->narrationPublishState() === 'published' ? 'warning' : 'success';
                })
                ->requiresConfirmation()
                ->modalHeading(function (): string {
                    return $this->narrationPublishState() === 'published'
                        ? 'Impostare la narrazione come bozza?'
                        : 'Pubblicare la narrazione?';
                })
                ->modalDescription(function (): string {
                    return $this->narrationPublishState() === 'published'
                        ? 'Lo stato passerà da published a draft.'
                        : 'Lo stato passerà da draft a published.';
                })
                ->modalSubmitActionLabel(function (): string {
                    return $this->narrationPublishState() === 'published' ? 'Unpublish' : 'Publish';
                })
                ->action(function (): void {
                    /** @var Narration $record */
                    $record = $this->getRecord();
                    $current = $this->normalizeNarrationPublishState($record->publish_state);
                    $newState = $current === 'published' ? 'draft' : 'published';
                    $record->update(['publish_state' => $newState]);
                    $record->refresh();
                    Notification::make()
                        ->title('Stato aggiornato')
                        ->body('publish_state impostato a "'.$newState.'".')
                        ->success()
                        ->send();
                }),
            Actions\ViewAction::make(),
            Actions\DeleteAction::make(),
        ];
    }

    /** Stato publish per UI: draft se assente o non riconosciuto. */
    private function narrationPublishState(): string
    {
        /** @var Narration $record */
        $record = $this->getRecord();

        return $this->normalizeNarrationPublishState($record->publish_state);
    }

    private function normalizeNarrationPublishState(mixed $state): string
    {
        $s = is_string($state) ? strtolower(trim($state)) : '';

        return $s === 'published' ? 'published' : 'draft';
    }

    /**
     * Footer: modale Add Media (stesso pattern di Mirror Data -> Step2 Record Details).
     */
    public function getFooter(): ?View
    {
        return view('filament.resources.narration-resource.pages.edit-narration-add-media-modal');
    }

    public function openAddMediaModal(): void
    {
        $this->addMediaFile = null;
        $this->showAddMediaModal = true;
    }

    public function closeAddMediaModal(): void
    {
        $this->showAddMediaModal = false;
        $this->addMediaFile = null;
    }

    /** Estensioni ammesse (immagini), come in Mirror Data Add Media. */
    private static function allowedImageExtensions(): array
    {
        return ['jpg', 'jpeg', 'png', 'tiff', 'tif', 'gif', 'webp', 'bmp'];
    }

    /**
     * Conferma upload: salva il file in INGEST_FS_ROOT/ID/nomeimmagine.
     * Crea la cartella ID se non esiste (config da .env: INGEST_FS_ROOT).
     */
    public function confirmAddMedia(): void
    {
        if (! $this->addMediaFile instanceof TemporaryUploadedFile) {
            Notification::make()
                ->title('Errore')
                ->body('Selezionare un file immagine.')
                ->danger()
                ->send();

            return;
        }

        $ext = strtolower($this->addMediaFile->getClientOriginalExtension());
        if (! in_array($ext, self::allowedImageExtensions(), true)) {
            Notification::make()
                ->title('Errore')
                ->body('Estensione non consentita. Consentite: '.implode(', ', self::allowedImageExtensions()).'.')
                ->danger()
                ->send();

            return;
        }

        $record = $this->getRecord();
        $narrationId = $record->getKey();
        $ingestionRoot = IngestionPaths::root();
        $targetDir = $ingestionRoot.DIRECTORY_SEPARATOR.$narrationId;

        if (! is_dir($targetDir)) {
            if (! mkdir($targetDir, 0755, true)) {
                Notification::make()
                    ->title('Errore')
                    ->body('Impossibile creare la cartella per la narrazione.')
                    ->danger()
                    ->send();
                $this->closeAddMediaModal();

                return;
            }
        }

        $baseName = preg_replace('/[^a-zA-Z0-9._-]/', '_', $this->addMediaFile->getClientOriginalName()) ?: 'image';
        if (! str_contains($baseName, '.')) {
            $baseName .= '.'.$ext;
        }
        $destPath = $targetDir.DIRECTORY_SEPARATOR.$baseName;
        $counter = 1;
        while (file_exists($destPath)) {
            $baseName = pathinfo($baseName, PATHINFO_FILENAME).'_'.$counter.'.'.$ext;
            $destPath = $targetDir.DIRECTORY_SEPARATOR.$baseName;
            $counter++;
        }

        try {
            $tmpPath = $this->addMediaFile->getRealPath();
            if (! $tmpPath || ! is_file($tmpPath)) {
                throw new \RuntimeException('File temporaneo non disponibile');
            }
            if (! copy($tmpPath, $destPath)) {
                throw new \RuntimeException('Copia file fallita');
            }
        } catch (\Throwable $e) {
            \Log::error('Narration Add Media: failed to save file', [
                'narration_id' => $narrationId,
                'dest' => $destPath,
                'error' => $e->getMessage(),
            ]);
            Notification::make()
                ->title('Errore')
                ->body('Impossibile salvare il file: '.$e->getMessage())
                ->danger()
                ->send();
            $this->closeAddMediaModal();

            return;
        }

        $this->closeAddMediaModal();
        Notification::make()
            ->title('Media aggiunto')
            ->body('Il file è stato salvato in '.$targetDir.'.')
            ->success()
            ->send();
    }
}
