<?php

declare(strict_types=1);

namespace App\Filament\Resources\MirrorInstanceResource\Pages;

use App\Filament\Resources\MirrorInstanceResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListMirrorInstances extends ListRecords
{
    protected static string $resource = MirrorInstanceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
