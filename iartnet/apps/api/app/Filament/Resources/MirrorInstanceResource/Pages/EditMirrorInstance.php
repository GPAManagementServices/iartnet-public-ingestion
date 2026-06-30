<?php

declare(strict_types=1);

namespace App\Filament\Resources\MirrorInstanceResource\Pages;

use App\Filament\Resources\MirrorInstanceResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditMirrorInstance extends EditRecord
{
    protected static string $resource = MirrorInstanceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()
                ->requiresConfirmation()
                ->modalHeading('Delete Mirror Instance')
                ->modalDescription(fn (): string => 
                    "Are you sure you want to delete '{$this->record->display_name}'? This will also delete the PostgreSQL schema '{$this->record->name}'. This action cannot be undone."
                )
                ->modalSubmitActionLabel('Delete')
                ->disabled(fn (): bool => $this->record->is_protected),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
