<?php

declare(strict_types=1);

namespace App\Filament\Resources\InterviewResource\Pages;

use App\Filament\Resources\InterviewResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditInterview extends EditRecord
{
    protected static string $resource = InterviewResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\ViewAction::make(),
            InterviewResource::configureDeleteInterviewAction(Actions\DeleteAction::make()),
        ];
    }
}
