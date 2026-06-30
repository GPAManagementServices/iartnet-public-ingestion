<?php

declare(strict_types=1);

namespace App\Filament\Resources\NarrationResource\Pages;

use App\Filament\Resources\NarrationResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewNarration extends ViewRecord
{
    protected static string $resource = NarrationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make(),
        ];
    }
}
