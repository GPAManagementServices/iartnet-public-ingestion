<?php

declare(strict_types=1);

namespace App\Filament\Resources\NarrationResource\Pages;

use App\Filament\Resources\NarrationResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListNarrations extends ListRecords
{
    protected static string $resource = NarrationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
