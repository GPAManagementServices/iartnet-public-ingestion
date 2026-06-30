<?php

declare(strict_types=1);

namespace App\Filament\Resources\MasterDataCardResource\Pages;

use App\Filament\Resources\MasterDataCardResource;
use App\Filament\Resources\MasterDataCardResource\Pages\Concerns\HasCardDetailContent;
use Filament\Resources\Pages\Page;

/**
 * Dettaglio scheda Master Data: stessa logica di ListMasterDataCards (trait HasCardDetailContent).
 */
class ViewMasterDataCard extends Page
{
    use HasCardDetailContent;

    protected static string $resource = MasterDataCardResource::class;

    protected string $view = 'filament.resources.master-data-card-resource.pages.view-master-data-card';

    /** stable_id dalla route */
    public string $recordId = '';

    public function mount(string $record): void
    {
        $this->clearRecordDetailInlineEditing();
        $this->recordId = $record;
        $this->images = collect([]);
        $this->loadCardRecordData();
        $this->loadCardImages();
    }

    public function getCardDetailRecordId(): string
    {
        return $this->recordId;
    }

    public function returnToMainTable(): void
    {
        $this->redirect(MasterDataCardResource::getUrl('index'));
    }

    public function getTitle(): string
    {
        return 'Card details';
    }
}
