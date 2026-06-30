<?php

declare(strict_types=1);

namespace App\Filament\Resources\NarrationResource\Pages;

use App\Filament\Resources\NarrationResource;
use App\Models\Narration;
use App\Services\Narration\NarrationStoryPayloadMapper;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;

class StoryEditorNarration extends Page
{
    use InteractsWithRecord;

    protected static string $resource = NarrationResource::class;

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $title = 'Story Editor';

    protected string $view = 'filament.resources.narration-resource.pages.story-editor-narration';

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
        $this->mountCanAuthorizeAccess();
    }

    /**
     * @return array<string, mixed>
     */
    public function getStoryInitPayload(): array
    {
        /** @var Narration $narration */
        $narration = $this->getRecord();

        return NarrationStoryPayloadMapper::toStoryEditorPayload($narration);
    }

    /**
     * @param  array<string, mixed>  $extJson
     */
    public function saveStoryFromEditor(array $extJson): void
    {
        /** @var Narration $narration */
        $narration = $this->getRecord();

        $narration->update([
            'ext_json' => NarrationStoryPayloadMapper::extJsonFromEditorSave($extJson),
            'updated_at' => now(),
        ]);

        $narration->refresh();

        Notification::make()
            ->title('Story salvata')
            ->body('Il contenuto ext_json è stato aggiornato.')
            ->success()
            ->send();
    }

    public function getEditUrl(): string
    {
        return NarrationResource::getUrl('edit', ['record' => $this->getRecord()]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('backToEdit')
                ->label('Torna alla narrazione')
                ->icon('heroicon-o-arrow-left')
                ->url(NarrationResource::getUrl('edit', ['record' => $this->getRecord()])),
        ];
    }
}
