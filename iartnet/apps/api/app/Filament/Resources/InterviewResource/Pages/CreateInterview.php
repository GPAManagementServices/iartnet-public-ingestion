<?php

declare(strict_types=1);

namespace App\Filament\Resources\InterviewResource\Pages;

use App\Filament\Resources\InterviewResource;
use Filament\Resources\Pages\CreateRecord;

class CreateInterview extends CreateRecord
{
    protected static string $resource = InterviewResource::class;
}
