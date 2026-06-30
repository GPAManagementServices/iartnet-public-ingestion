<?php

declare(strict_types=1);

namespace App\Filament\Resources\MasterDataCardResource\Pages;

use App\Filament\Resources\MasterDataCardResource;
use App\Filament\Resources\MasterDataCardResource\Pages\Concerns\HasCardDetailContent;
use App\Models\Institution;
use App\Models\MasterDcRecord;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\RenderHook;
use Filament\Schemas\Components\View as SchemaView;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Filament\View\PanelsRenderHook;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;

class ListMasterDataCards extends ListRecords
{
    use HasCardDetailContent;

    protected static string $resource = MasterDataCardResource::class;

    /** Institution selected in the filter (UUID). Default: first institution. */
    public ?string $institutionId = null;

    /** Card type filter: TUTTE, OA, D, S, MI, MIDF, MINV, ALTRE. */
    public string $cardType = 'TUTTE';

    /** True after user has pressed SEARCH; until then table stays empty. */
    public bool $hasSearched = false;

    /** true = mostra sezione Card Details e nasconde tabella; false = mostra tabella e nasconde Card Details. */
    public bool $showingCardDetail = false;

    /** stable_id della scheda di cui si visualizza il dettaglio (quando showingCardDetail = true). */
    public ?string $viewingStableId = null;

    public function mount(): void
    {
        parent::mount();
        $this->images = collect([]);

        $scopedInstitutionId = auth()->user()?->getScopedInstitutionId();
        if ($scopedInstitutionId !== null) {
            $this->institutionId = $scopedInstitutionId;

            return;
        }

        $first = Institution::query()->orderBy('name')->first();
        if ($first !== null) {
            $this->institutionId = $first->id;
        }
    }

    public function getCardDetailRecordId(): string
    {
        return $this->viewingStableId ?? '';
    }

    /**
     * Apre la sezione Card Details nella stessa pagina: popola dati, nasconde tabella, mostra Card Details.
     */
    public function openCardDetail(string $stableId): void
    {
        $this->clearRecordDetailInlineEditing();
        $this->viewingStableId = $stableId;
        $this->cardDetailActiveTab = 'originalFields';
        $this->loadCardRecordData();
        $this->loadCardImages();
        $this->showingCardDetail = true;
    }

    /**
     * Torna alla tabella: nasconde Card Details, mostra tabella (stesso stato, no reload).
     */
    public function returnToMainTable(): void
    {
        $this->clearRecordDetailInlineEditing();
        $this->showingCardDetail = false;
    }

    /**
     * Azione View definita qui così che $this->openCardDetail() sia nel contesto corretto.
     *
     * Filament, di default, imposta recordUrl verso la pagina "view" del resource usando la
     * chiave primaria Eloquent (qui: id UUID). La route ViewMasterDataCard invece deve ricevere
     * stable_id; il click sulla riga apriva quindi /view/{uuid} e il dettaglio risultava vuoto.
     * Disabilitando recordUrl e usando recordAction('view'), il click sulla riga invoca la stessa
     * azione tabellare del pulsante View (openCardDetail con stable_id).
     */
    public function table(Table $table): Table
    {
        $viewAction = Action::make('view')
            ->label('View')
            ->icon('heroicon-o-eye')
            ->color('primary')
            ->action(function (MasterDcRecord $record): void {
                $this->openCardDetail($record->stable_id);
            });

        $publishAllDraftAction = Action::make('publishAllDraft')
            ->label('Publish all draft')
            ->icon('heroicon-o-arrow-up-circle')
            ->color('success')
            ->requiresConfirmation()
            ->modalHeading('Publish all draft cards')
            ->modalDescription(function (): string {
                $institutionName = Institution::query()->find($this->institutionId)?->name ?? '—';
                $cardTypeLabel = $this->getSelectedCardTypeLabel();

                return "Publish all the cards type {$cardTypeLabel} of the Institution {$institutionName}?";
            })
            ->modalSubmitActionLabel('Publish all')
            ->action(function (): void {
                $query = MasterDcRecord::query()->where('publish_state', 'draft');
                $this->applyInstitutionAndCardTypeScope($query);
                $recordIds = $query->pluck('id');

                $count = DB::table('iartnet_master.records')
                    ->whereIn('id', $recordIds)
                    ->where('publish_state', 'draft')
                    ->update(['publish_state' => 'published']);

                Notification::make()
                    ->title('Publish completed')
                    ->body("Updated {$count} card(s) to published.")
                    ->success()
                    ->send();
            });

        return $table
            ->recordUrl(null)
            ->recordAction('view')
            ->headerActions([$publishAllDraftAction])
            ->actions(array_merge([$viewAction], $table->getActions()));
    }

    /**
     * Table is empty until SEARCH is pressed; then filter by institution and card type.
     */
    protected function getTableQuery(): Builder|Relation|null
    {
        $query = MasterDcRecord::query();
        if (! $this->hasSearched) {
            return $query->whereRaw('1 = 0');
        }
        $this->applyInstitutionAndCardTypeScope($query);

        return $query;
    }

    /**
     * Applica i filtri header Institution + CardType (stessa logica di SEARCH).
     */
    private function applyInstitutionAndCardTypeScope(Builder $query): Builder
    {
        if ($this->institutionId !== null && $this->institutionId !== '') {
            $query->where('primary_institution_id', $this->institutionId);
        }
        if ($this->cardType !== 'TUTTE') {
            if ($this->cardType === 'ALTRE') {
                $query->where(function (Builder $q): void {
                    $q->whereNull('c_type')->orWhere('c_type', '');
                });
            } else {
                $query->where('c_type', $this->cardType);
            }
        }

        return $query;
    }

    /**
     * Etichetta CardType mostrata nel messaggio di conferma (allineata al combo header).
     */
    private function getSelectedCardTypeLabel(): string
    {
        return match ($this->cardType) {
            'TUTTE' => 'ALL',
            'INTERVISTA' => 'INTERVIEW',
            'SALON' => 'SALON_N',
            default => $this->cardType,
        };
    }

    public function search(): void
    {
        try {
            auth()->user()?->assertCanAccessInstitution($this->institutionId);
        } catch (\RuntimeException $e) {
            Notification::make()
                ->title('Errore')
                ->body($e->getMessage())
                ->danger()
                ->send();

            return;
        }

        $this->hasSearched = true;
    }

    /**
     * Header: Institutions combo, CardType combo, SEARCH button. Nascosto quando è visibile Card Details.
     */
    public function getHeader(): ?View
    {
        if ($this->showingCardDetail) {
            return null;
        }

        $scopedInstitutionId = auth()->user()?->getScopedInstitutionId();
        $institutionsQuery = Institution::query()->orderBy('name');
        if ($scopedInstitutionId !== null) {
            $institutionsQuery->where('id', $scopedInstitutionId);
        }

        return view('filament.resources.master-data-card-resource.pages.list-master-data-cards-header', [
            'institutions' => $institutionsQuery->get(),
        ]);
    }

    /**
     * Stessa pagina: tabella e Card Details entrambe nel DOM; visibilità alternata (hidden) in base a showingCardDetail.
     */
    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                $this->getTabsContentComponent(),
                RenderHook::make(PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_BEFORE),
                \Filament\Schemas\Components\EmbeddedTable::make()
                    ->hidden(fn (): bool => $this->showingCardDetail),
                RenderHook::make(PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_AFTER),
                SchemaView::make('filament.resources.master-data-card-resource.pages.card-detail-embed')
                    ->hidden(fn (): bool => ! $this->showingCardDetail),
            ]);
    }
}
