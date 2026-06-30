<?php

declare(strict_types=1);

namespace App\Filament\Resources\MirrorInstanceResource\Pages;

use App\Filament\Resources\MirrorInstanceResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Log;

class CreateMirrorInstance extends CreateRecord
{
    protected static string $resource = MirrorInstanceResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    /**
     * Mutate the form data before it is created.
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        Log::info("CreateMirrorInstance::mutateFormDataBeforeCreate", [
            'data' => $data,
        ]);

        return $data;
    }

    /**
     * Handle the record creation.
     */
    protected function handleRecordCreation(array $data): \Illuminate\Database\Eloquent\Model
    {
        Log::info("CreateMirrorInstance::handleRecordCreation", [
            'data' => $data,
        ]);

        $record = parent::handleRecordCreation($data);

        Log::info("CreateMirrorInstance::handleRecordCreation completed", [
            'record_id' => $record->id,
            'record_name' => $record->name,
        ]);

        return $record;
    }
}
