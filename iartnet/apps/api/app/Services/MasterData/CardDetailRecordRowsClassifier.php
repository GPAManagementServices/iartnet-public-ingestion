<?php

declare(strict_types=1);

namespace App\Services\MasterData;

use Symfony\Component\Yaml\Yaml;

/**
 * Classifica le righe piatte del JSON scheda (v_record_full_json_en) per i tab Card Details:
 * Added Fields (ad_*), Metadata (mapping + sezioni EDM), Original Fields (resto di record_fields).
 */
final class CardDetailRecordRowsClassifier
{
    /**
     * SBN → sbn-to-master.yaml; JSON → json-to-master.yaml; tutto il resto (ICCD) → iccd-to-master.yaml.
     */
    public function resolveMappingBasename(?string $cardTypeRaw): string
    {
        $t = strtoupper(trim((string) $cardTypeRaw));

        return match ($t) {
            'SBN' => 'sbn-to-master.yaml',
            'JSON' => 'json-to-master.yaml',
            default => 'iccd-to-master.yaml',
        };
    }

    /**
     * Legge record_fields.card_type dalla struttura record_json (primo valore utile).
     */
    public function extractCardTypeFromRecordJson(array $recordJson): ?string
    {
        $fields = $recordJson['record_fields'] ?? null;
        if (! is_array($fields)) {
            return null;
        }
        $ct = $fields['card_type'] ?? null;
        if (is_array($ct)) {
            foreach ($ct as $item) {
                if (is_array($item) && array_key_exists('value', $item)) {
                    return strtoupper(trim((string) $item['value']));
                }
            }

            return null;
        }
        if (is_scalar($ct)) {
            return strtoupper(trim((string) $ct));
        }

        return null;
    }

    /**
     * Nomi campo sotto record_fields considerati "Metadata" perché mappati verso record_kv (kv_key)
     * o colonne records (target_field diverso da ext_json).
     *
     * @return array<string, true>
     */
    public function loadMetadataRecordFieldNames(string $mappingBasename): array
    {
        $path = $this->resolveMappingFilePath($mappingBasename);
        if ($path === null || ! is_readable($path)) {
            return [];
        }
        $parsed = Yaml::parseFile($path);
        if (! is_array($parsed)) {
            return [];
        }
        $mappings = $parsed['mappings'] ?? [];
        $names = [];
        foreach ($mappings as $rule) {
            if (! is_array($rule)) {
                continue;
            }
            $targetTable = $rule['target_table'] ?? '';
            if ($targetTable === 'record_kv' && ! empty($rule['kv_key'])) {
                $names[(string) $rule['kv_key']] = true;
                continue;
            }
            if ($targetTable === 'records') {
                $tf = (string) ($rule['target_field'] ?? '');
                if ($tf !== '' && $tf !== 'ext_json') {
                    $names[$tf] = true;
                }
            }
        }

        return $names;
    }

    /**
     * @param  list<array{key: string, value: string}>  $flatRows
     * @param  array<string, true>  $metadataRecordFieldNames
     * @return array{
     *     added: list<array{key: string, value: string}>,
     *     metadata: list<array{key: string, value: string}>,
     *     original: list<array{key: string, value: string}>
     * }
     */
    public function partitionFlatRows(array $flatRows, array $metadataRecordFieldNames): array
    {
        $added = [];
        $metadata = [];
        $original = [];

        foreach ($flatRows as $row) {
            $key = (string) ($row['key'] ?? '');
            $bucket = $this->classifyRowKey($key, $metadataRecordFieldNames);
            if ($bucket === 'added') {
                $added[] = $row;
            } elseif ($bucket === 'metadata') {
                $metadata[] = $row;
            } else {
                $original[] = $row;
            }
        }

        return [
            'added' => $added,
            'metadata' => $metadata,
            'original' => $original,
        ];
    }

    /**
     * @param  array<string, mixed>  $recordJson
     * @param  list<array{key: string, value: string}>  $flatRows
     * @return array{
     *     added: list<array{key: string, value: string}>,
     *     metadata: list<array{key: string, value: string}>,
     *     original: list<array{key: string, value: string}>,
     *     mapping_basename: string,
     *     card_type: ?string
     * }
     */
    public function partitionFromRecordJson(array $recordJson, array $flatRows): array
    {
        $cardType = $this->extractCardTypeFromRecordJson($recordJson);
        $basename = $this->resolveMappingBasename($cardType);
        $metaNames = $this->loadMetadataRecordFieldNames($basename);
        $parts = $this->partitionFlatRows($flatRows, $metaNames);

        return [
            ...$parts,
            'mapping_basename' => $basename,
            'card_type' => $cardType,
        ];
    }

    /**
     * Ordine: 1) ad_*  2) sezioni EDM (media, agents, places, dates, edm_type, …)  3) record_fields mappati  4) original.
     *
     * @param  array<string, true>  $metadataRecordFieldNames
     */
    private function classifyRowKey(string $key, array $metadataRecordFieldNames): string
    {
        if (preg_match('/^record_fields\.ad_/i', $key) === 1) {
            return 'added';
        }

        if ($this->isStructuralMetadataKey($key)) {
            return 'metadata';
        }

        if (str_starts_with($key, 'record_fields.')) {
            $fieldName = substr($key, strlen('record_fields.'));
            if ($fieldName !== '' && isset($metadataRecordFieldNames[$fieldName])) {
                return 'metadata';
            }

            return 'original';
        }

        return 'original';
    }

    /**
     * Sezioni richieste in Metadata (oltre ai campi da mapping YAML).
     */
    private function isStructuralMetadataKey(string $key): bool
    {
        if ($key === 'edm_type' || str_starts_with($key, 'edm_type.')) {
            return true;
        }
        if ($key === 'dates' || str_starts_with($key, 'dates.')) {
            return true;
        }
        $prefixes = ['media.', 'agents.', 'places.', 'concepts.', 'timespans.', 'relations.'];
        foreach ($prefixes as $prefix) {
            if (str_starts_with($key, $prefix)) {
                return true;
            }
        }
        if ($key === 'relations' || $key === 'concepts' || $key === 'timespans' || $key === 'media'
            || $key === 'agents' || $key === 'places') {
            return true;
        }

        return false;
    }

    private function resolveMappingFilePath(string $mappingBasename): ?string
    {
        $local = storage_path('mapping/local/'.$mappingBasename);
        if (is_readable($local)) {
            return $local;
        }
        $base = storage_path('mapping/base/'.$mappingBasename);
        if (is_readable($base)) {
            return $base;
        }

        return null;
    }
}
