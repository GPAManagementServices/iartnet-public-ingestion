<?php

declare(strict_types=1);

namespace App\Filament\Resources\InterviewResource\Pages;

use App\Filament\Resources\InterviewResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListInterviews extends ListRecords
{
    protected static string $resource = InterviewResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('importInterview')
                ->label('Import Interview')
                ->icon('heroicon-o-arrow-up-tray')
                ->url(InterviewResource::getUrl('import')),
        ];
    }
}
