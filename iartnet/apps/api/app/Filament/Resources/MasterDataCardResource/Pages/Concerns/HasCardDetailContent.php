<?php

declare(strict_types=1);

namespace App\Filament\Resources\MasterDataCardResource\Pages\Concerns;

use App\Services\MasterData\CardDetailI18nTextManualWriter;
use App\Services\MasterData\CardDetailRecordRowsClassifier;
use Filament\Notifications\Notification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Logica condivisa per la visualizzazione del dettaglio scheda (Card Details).
 * Usato da ViewMasterDataCard e da ListMasterDataCards (stessa pagina, sezione visible/hidden).
 */
trait HasCardDetailContent
{
    /**
     * Tab attiva: 'originalFields' | 'metadata' | 'addedFields' | 'imagesPreview'.
     * Images Preview: anteprima IIIF + toggle publish_state ('draft'|'published' su web_resources) per immagine selezionata.
     */
    public string $cardDetailActiveTab = 'originalFields';

    /**
     * Lista piatta completa (post-flatten), utile per conteggi totali oppure debug.
     *
     * @var list<array{key: string, value: string}>|null
     */
    public ?array $recordTableRows = null;

    /** Righe per tab "Original Fields" (mirror / xpath, non mappate come metadata). */
    public array $recordDetailRowsOriginal = [];

    /** Righe per tab "Metadata" (mapping YAML + sezioni media, agents, places, dates, edm_type, …). */
    public array $recordDetailRowsMetadata = [];

    /** Righe per tab "Added Fields" (record_fields.ad_*). */
    public array $recordDetailRowsAdded = [];

    /** Testo di ricerca per filtrare la tabella del tab record attivo (Key e Value). */
    public string $recordDetailsSearch = '';

    /** Master record_id (UUID) per query web_resources. */
    public ?string $masterRecordId = null;

    /** Immagini da web_resources (url IIIF, ord). */
    public Collection $images;

    /** Indice immagine selezionata nel tab Images (0-based). */
    public int $selectedImageIndex = 0;

    /** Chiave flatten (KEY) della riga in editing inline sul valore; una sola riga alla volta. */
    public ?string $recordDetailEditingRowKey = null;

    public string $recordDetailEditingDraftValue = '';

    public string $recordDetailEditingBaselineValue = '';

    /**
     * Carica i dati dalla view iartnet_master.v_record_full_json (stable_id = recordId).
     * Usa getCardDetailRecordId() per lo stable_id.
     */
    protected function loadCardRecordData(): void
    {
        $recordId = $this->getCardDetailRecordId();
        if ($recordId === '') {
            $this->resetRecordDetailPartitions();

            return;
        }

        $row = DB::selectOne(
            'SELECT record_id, stable_id, record_json FROM iartnet_master.v_record_full_json_en WHERE stable_id = ?',
            [$recordId]
        );

        if ($row === null) {
            $this->resetRecordDetailPartitions();

            return;
        }

        $this->masterRecordId = (string) $row->record_id;
        $recordJson = $row->record_json;
        if (is_string($recordJson)) {
            $recordJson = json_decode($recordJson, true);
        }
        if (is_object($recordJson)) {
            $recordJson = (array) $recordJson;
        }

        if (! is_array($recordJson)) {
            $this->resetRecordDetailPartitions();

            return;
        }

        $flat = $this->flattenRecordJson($recordJson);
        $this->recordTableRows = $flat;

        $classifier = app(CardDetailRecordRowsClassifier::class);
        $parts = $classifier->partitionFromRecordJson($recordJson, $flat);
        $this->recordDetailRowsOriginal = $parts['original'];
        $this->recordDetailRowsMetadata = $parts['metadata'];
        $this->recordDetailRowsAdded = $parts['added'];
    }

    /** Azzera flatten e partizioni (nessun dato o errore struttura). */
    protected function resetRecordDetailPartitions(): void
    {
        $this->recordTableRows = [];
        $this->recordDetailRowsOriginal = [];
        $this->recordDetailRowsMetadata = [];
        $this->recordDetailRowsAdded = [];
        $this->masterRecordId = null;
    }

    /** Restituisce lo stable_id del record da visualizzare (ViewMasterDataCard: recordId; ListMasterDataCards: viewingStableId). */
    abstract public function getCardDetailRecordId(): string;

    public function updatedCardDetailActiveTab(): void
    {
        $this->clearRecordDetailInlineEditing();
    }

    public function updatedRecordDetailsSearch(): void
    {
        $this->clearRecordDetailInlineEditing();
    }

    public function clearRecordDetailInlineEditing(): void
    {
        $this->recordDetailEditingRowKey = null;
        $this->recordDetailEditingDraftValue = '';
        $this->recordDetailEditingBaselineValue = '';
    }

    public function isRecordDetailValueRowEditing(string $key): bool
    {
        return $this->recordDetailEditingRowKey !== null
            && $this->recordDetailEditingRowKey === $key;
    }

    public function isRecordDetailValueRowEditable(string $key): bool
    {
        if ($this->recordDetailRowKeyToI18nFieldName($key) === null) {
            return false;
        }

        return match ($this->cardDetailActiveTab) {
            'addedFields' => true,
            'originalFields', 'metadata' => str_starts_with(trim($key), 'record_fields.'),
            default => false,
        };
    }

    /**
     * Mappa la KEY mostrata al field_name in i18n_texts (senza prefisso record_fields. se presente).
     * Nel tab Added Fields tutte le righe sono editabili: se manca il prefisso si usa la KEY intera.
     */
    protected function recordDetailRowKeyToI18nFieldName(string $key): ?string
    {
        $stripped = CardDetailI18nTextManualWriter::displayKeyToFieldName($key);
        if ($stripped !== null) {
            return $stripped;
        }
        if ($this->cardDetailActiveTab === 'addedFields') {
            $t = trim($key);

            return $t !== '' ? $t : null;
        }

        return null;
    }

    public function startEditRecordDetailRow(string $key, string $currentValue): void
    {
        if (! $this->isRecordDetailValueRowEditable($key)) {
            return;
        }
        $this->recordDetailEditingRowKey = $key;
        $this->recordDetailEditingBaselineValue = $currentValue;
        $this->recordDetailEditingDraftValue = $currentValue;
    }

    public function cancelEditRecordDetailRow(): void
    {
        $this->clearRecordDetailInlineEditing();
    }

    public function saveEditRecordDetailRow(): void
    {
        $key = $this->recordDetailEditingRowKey;
        if ($key === null || $key === '' || $this->masterRecordId === null || $this->masterRecordId === '') {
            Notification::make()
                ->title('Unable to save')
                ->body('No row or record is selected.')
                ->warning()
                ->send();

            return;
        }
        if (! $this->isRecordDetailValueRowEditable($key)) {
            $this->clearRecordDetailInlineEditing();

            return;
        }
        $fieldName = $this->recordDetailRowKeyToI18nFieldName($key);
        if ($fieldName === null) {
            $this->clearRecordDetailInlineEditing();

            return;
        }

        try {
            app(CardDetailI18nTextManualWriter::class)->upsertRecordEnglishManual(
                $this->masterRecordId,
                $fieldName,
                $this->recordDetailEditingDraftValue
            );
        } catch (\Throwable $e) {
            Notification::make()
                ->title('Save failed')
                ->body($e->getMessage())
                ->danger()
                ->send();

            return;
        }

        $this->patchRecordDetailRowValueInMemory($key, $this->recordDetailEditingDraftValue);
        $this->clearRecordDetailInlineEditing();
        Notification::make()
            ->title('Value saved')
            ->body('i18n_texts updated (lang=en, origin=manual).')
            ->success()
            ->send();
    }

    /**
     * @param  list<array{key: string, value: string}>  $rows
     * @return list<array{key: string, value: string}>
     */
    protected function patchKeyedRowValue(array $rows, string $key, string $newValue): array
    {
        $out = [];
        foreach ($rows as $row) {
            if (($row['key'] ?? '') === $key) {
                $row['value'] = $newValue;
            }
            $out[] = $row;
        }

        return $out;
    }

    protected function patchRecordDetailRowValueInMemory(string $key, string $newValue): void
    {
        $this->recordDetailRowsOriginal = $this->patchKeyedRowValue($this->recordDetailRowsOriginal, $key, $newValue);
        $this->recordDetailRowsMetadata = $this->patchKeyedRowValue($this->recordDetailRowsMetadata, $key, $newValue);
        $this->recordDetailRowsAdded = $this->patchKeyedRowValue($this->recordDetailRowsAdded, $key, $newValue);
        if (is_array($this->recordTableRows)) {
            $this->recordTableRows = $this->patchKeyedRowValue($this->recordTableRows, $key, $newValue);
        }
    }

    /**
     * Righe del tab dati attivo (Original / Metadata / Added), filtrate per ricerca.
     *
     * @return list<array{key: string, value: string}>
     */
    public function getFilteredRecordDetailRows(): array
    {
        $source = match ($this->cardDetailActiveTab) {
            'metadata' => $this->recordDetailRowsMetadata,
            'addedFields' => $this->recordDetailRowsAdded,
            'originalFields' => $this->recordDetailRowsOriginal,
            default => [],
        };
        if ($source === []) {
            return [];
        }
        $term = trim($this->recordDetailsSearch);
        if ($term === '') {
            return $source;
        }
        $termLower = mb_strtolower($term);
        $filtered = [];
        foreach ($source as $row) {
            $key = $row['key'] ?? '';
            $value = (string) ($row['value'] ?? '');
            if (mb_strpos(mb_strtolower($key), $termLower) !== false
                || mb_strpos(mb_strtolower($value), $termLower) !== false) {
                $filtered[] = $row;
            }
        }

        return $filtered;
    }

    /** Conteggio righe nel tab dati corrente (prima del filtro ricerca). */
    public function getRecordDetailTabRowCount(): int
    {
        return match ($this->cardDetailActiveTab) {
            'metadata' => count($this->recordDetailRowsMetadata),
            'addedFields' => count($this->recordDetailRowsAdded),
            'originalFields' => count($this->recordDetailRowsOriginal),
            default => 0,
        };
    }

    public function getKeyCellClasses(string $key): string
    {
        $key = trim($key);
        if (str_starts_with($key, 'record_fields.')) {
            $fieldName = substr($key, strlen('record_fields.'));
            if (str_starts_with($fieldName, 'ad_')) {
                return 'text-fuchsia-600 dark:text-fuchsia-400 bg-white dark:bg-gray-800';
            }
            if (str_contains($fieldName, '/')) {
                return 'text-gray-900 dark:text-gray-100';
            }
        }

        return 'text-sky-600 dark:text-sky-400 bg-white dark:bg-gray-800';
    }

    /** @param  array<string, mixed>  $recordJson
     * @return list<array{key: string, value: string}>
     */
    protected function flattenRecordJson(array $recordJson): array
    {
        $rows = [];
        foreach ($recordJson as $sectionKey => $value) {
            if (is_scalar($value) || $value === null) {
                $rows[] = ['key' => $sectionKey, 'value' => (string) $value];
            } elseif ($sectionKey === 'record_fields' && is_array($value)) {
                foreach ($this->flattenRecordFields($value) as $item) {
                    $rows[] = ['key' => 'record_fields.'.$item['key'], 'value' => $item['value']];
                }
            } elseif (in_array($sectionKey, ['agents', 'media', 'places'], true) && is_array($value)) {
                foreach ($this->flattenSectionItems($sectionKey, $value) as $item) {
                    $rows[] = $item;
                }
            } elseif (is_array($value)) {
                foreach ($this->flattenGenericSection($sectionKey, $value) as $item) {
                    $rows[] = $item;
                }
            } else {
                $rows[] = ['key' => $sectionKey, 'value' => $this->formatComplexValue((array) $value)];
            }
        }

        return $rows;
    }

    /** @param  array<string, mixed>  $recordFields
     * @return list<array{key: string, value: string}>
     */
    protected function flattenRecordFields(array $recordFields): array
    {
        $out = [];
        foreach ($recordFields as $fieldName => $items) {
            if (! is_array($items)) {
                $out[] = ['key' => $fieldName, 'value' => (string) $items];
                continue;
            }
            $values = [];
            foreach ($items as $item) {
                if (is_array($item) && isset($item['value'])) {
                    $values[] = $item['value'];
                }
            }
            $out[] = ['key' => $fieldName, 'value' => implode(' | ', $values)];
        }

        return $out;
    }

    /** @param  array<int, array<string, mixed>>  $items
     * @return list<array{key: string, value: string}>
     */
    protected function flattenSectionItems(string $sectionKey, array $items): array
    {
        $rows = [];
        foreach ($items as $index => $item) {
            if (! is_array($item)) {
                $rows[] = ['key' => $sectionKey.'.'.$index, 'value' => (string) $item];
                continue;
            }
            foreach ($item as $field => $val) {
                $flatKey = $sectionKey.'.'.$index.'.'.$field;
                if (is_scalar($val) || $val === null) {
                    $rows[] = ['key' => $flatKey, 'value' => (string) $val];
                } elseif (is_array($val)) {
                    $rows[] = ['key' => $flatKey, 'value' => $this->formatSectionFieldValue($val)];
                } else {
                    $rows[] = ['key' => $flatKey, 'value' => (string) $val];
                }
            }
        }

        return $rows;
    }

    /** @param  array<string, mixed>|array<int, mixed>  $value
     * @return list<array{key: string, value: string}>
     */
    protected function flattenGenericSection(string $sectionKey, array $value): array
    {
        $rows = [];
        $assoc = array_keys($value) !== range(0, count($value) - 1);
        if ($assoc) {
            foreach ($value as $k => $v) {
                if (is_scalar($v) || $v === null) {
                    $rows[] = ['key' => $sectionKey.'.'.$k, 'value' => (string) $v];
                } elseif (is_array($v)) {
                    $rows[] = ['key' => $sectionKey.'.'.$k, 'value' => $this->formatComplexValue($v)];
                } else {
                    $rows[] = ['key' => $sectionKey.'.'.$k, 'value' => (string) $v];
                }
            }
        } else {
            $rows[] = ['key' => $sectionKey, 'value' => $this->formatComplexValue($value)];
        }

        return $rows;
    }

    /** @param  array<int|string, mixed>  $value */
    protected function formatSectionFieldValue(array $value): string
    {
        if (isset($value['value']) && (is_scalar($value['value']) || $value['value'] === null)) {
            return (string) $value['value'];
        }
        if (array_keys($value) !== range(0, count($value) - 1)) {
            return $this->formatSingleComplexItem($value);
        }
        $parts = [];
        foreach ($value as $v) {
            if (is_scalar($v) || $v === null) {
                $parts[] = (string) $v;
            }
        }

        return implode(', ', $parts);
    }

    /** @param  array<int|string, mixed>  $value */
    protected function formatComplexValue(array $value): string
    {
        $parts = [];
        foreach ($value as $item) {
            if (is_scalar($item) || $item === null) {
                $parts[] = (string) $item;
            } elseif (is_array($item)) {
                $parts[] = $this->formatSingleComplexItem($item);
            }
        }
        if ($parts !== []) {
            return implode('; ', $parts);
        }

        return json_encode($value, JSON_UNESCAPED_UNICODE);
    }

    /** @param  array<string, mixed>  $item */
    protected function formatSingleComplexItem(array $item): string
    {
        if (isset($item['begin'], $item['end'])) {
            return $item['begin'].' / '.$item['end'];
        }
        if (isset($item['role'], $item['labels']) && is_array($item['labels'])) {
            return ($item['role'] ?? '').(implode(', ', $item['labels']) !== '' ? ': '.implode(', ', $item['labels']) : '');
        }
        if (isset($item['labels']) && is_array($item['labels'])) {
            return implode(', ', $item['labels']);
        }
        if (isset($item['url'])) {
            return $item['url'];
        }

        return json_encode($item, JSON_UNESCAPED_UNICODE);
    }

    protected function loadCardImages(): void
    {
        if ($this->masterRecordId === null) {
            $this->images = collect([]);

            return;
        }
        $items = DB::select(
            'SELECT id, url, role, ord, publish_state FROM iartnet_master.web_resources WHERE record_id = ? ORDER BY ord',
            [$this->masterRecordId]
        );
        $this->images = collect($items);
        $this->selectedImageIndex = 0;
    }

    /**
     * Stato pubblicazione immagine selezionata: 'draft' | 'published' (publish_state su web_resources).
     */
    public function getSelectedImagePublishStateLabel(): string
    {
        $img = $this->images->get($this->selectedImageIndex);
        if ($img === null) {
            return 'draft';
        }

        return $this->normalizeWebResourcePublishState($img->publish_state ?? null);
    }

    /**
     * Inverte draft ↔ published per la riga web_resources (id + record_id) dell'immagine corrente.
     */
    public function toggleSelectedImagePublishState(): void
    {
        if ($this->masterRecordId === null || $this->masterRecordId === '') {
            Notification::make()
                ->title('Unable to update')
                ->body('No master record is loaded.')
                ->warning()
                ->send();

            return;
        }

        $img = $this->images->get($this->selectedImageIndex);
        if ($img === null || empty($img->id)) {
            Notification::make()
                ->title('Unable to update')
                ->body('No image is selected.')
                ->warning()
                ->send();

            return;
        }

        $current = $this->normalizeWebResourcePublishState($img->publish_state ?? null);
        $newState = $current === 'published' ? 'draft' : 'published';

        $affected = DB::table('iartnet_master.web_resources')
            ->where('id', (string) $img->id)
            ->where('record_id', $this->masterRecordId)
            ->update([
                'publish_state' => $newState,
                'updated_at' => now(),
            ]);

        if ($affected === 0) {
            Notification::make()
                ->title('Update failed')
                ->body('No matching web_resources row was updated.')
                ->danger()
                ->send();

            return;
        }

        $img->publish_state = $newState;
        Notification::make()
            ->title('Image state saved')
            ->body("publish_state set to \"{$newState}\".")
            ->success()
            ->send();
    }

    public function canMoveSelectedImageUp(): bool
    {
        return $this->images->count() > 1 && $this->selectedImageIndex > 0;
    }

    public function canMoveSelectedImageDown(): bool
    {
        return $this->images->count() > 1 && $this->selectedImageIndex < $this->images->count() - 1;
    }

    public function moveSelectedImageUp(): void
    {
        $this->swapSelectedImageOrdWithNeighbor($this->selectedImageIndex - 1);
    }

    public function moveSelectedImageDown(): void
    {
        $this->swapSelectedImageOrdWithNeighbor($this->selectedImageIndex + 1);
    }

    /**
     * Scambia il campo ord tra l'immagine selezionata e il vicino (stessa card, scope record_id).
     */
    private function swapSelectedImageOrdWithNeighbor(int $neighborIndex): void
    {
        if ($this->masterRecordId === null || $this->masterRecordId === '') {
            Notification::make()
                ->title('Unable to reorder')
                ->body('No master record is loaded.')
                ->warning()
                ->send();

            return;
        }

        $currentIndex = $this->selectedImageIndex;
        if ($this->images->count() < 2 || $neighborIndex < 0 || $neighborIndex >= $this->images->count()) {
            return;
        }

        $current = $this->images->get($currentIndex);
        $neighbor = $this->images->get($neighborIndex);
        if ($current === null || $neighbor === null || empty($current->id) || empty($neighbor->id)) {
            Notification::make()
                ->title('Unable to reorder')
                ->body('No image is selected.')
                ->warning()
                ->send();

            return;
        }

        $currentOrd = $current->ord;
        $neighborOrd = $neighbor->ord;

        try {
            DB::transaction(function () use ($current, $neighbor, $currentOrd, $neighborOrd): void {
                $affectedCurrent = DB::table('iartnet_master.web_resources')
                    ->where('id', (string) $current->id)
                    ->where('record_id', $this->masterRecordId)
                    ->update([
                        'ord' => $neighborOrd,
                        'updated_at' => now(),
                    ]);

                $affectedNeighbor = DB::table('iartnet_master.web_resources')
                    ->where('id', (string) $neighbor->id)
                    ->where('record_id', $this->masterRecordId)
                    ->update([
                        'ord' => $currentOrd,
                        'updated_at' => now(),
                    ]);

                if ($affectedCurrent === 0 || $affectedNeighbor === 0) {
                    throw new \RuntimeException('No matching web_resources row was updated.');
                }
            });
        } catch (\Throwable $e) {
            Notification::make()
                ->title('Reorder failed')
                ->body($e->getMessage())
                ->danger()
                ->send();

            return;
        }

        $current->ord = $neighborOrd;
        $neighbor->ord = $currentOrd;
        $this->images = $this->images->sortBy('ord')->values();
        $this->selectedImageIndex = $neighborIndex;

        Notification::make()
            ->title('Image order updated')
            ->success()
            ->send();
    }

    /**
     * @return 'draft'|'published'
     */
    protected function normalizeWebResourcePublishState(mixed $raw): string
    {
        if ($raw === null || $raw === '') {
            return 'draft';
        }
        if (is_string($raw)) {
            $s = strtolower(trim($raw));
            if ($s === 'published') {
                return 'published';
            }
            if ($s === 'draft') {
                return 'draft';
            }
        }

        return 'draft';
    }

    public function getSelectedImageUrl(): ?string
    {
        $img = $this->images->get($this->selectedImageIndex);
        if ($img === null || empty($img->url)) {
            return null;
        }
        $url = $img->url;

        $url = str_starts_with($url, 'http://') || str_starts_with($url, 'https://')
            ? $url
            : 'http://'.$url;

        // Images Preview: IIIF size "max" → "pct:50" per ridurre il carico in anteprima.
        return str_replace('/max/', '/800,/', $url);
    }
}
