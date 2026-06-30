<?php

declare(strict_types=1);

namespace App\Services\Import;

use App\Models\MirrorRecord;
use App\Models\MirrorRecordKv;
use App\Models\MirrorAddedKv;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * MirrorToMasterImporter
 *
 * Service responsabile dell'importazione dati dallo Schema Mirror allo Schema Master.
 * Carica il mapping, legge record e KV dal Mirror, applica il mapping e scrive nel Master.
 */
class MirrorToMasterImporter
{
    /**
     * MappingResolver istanza.
     */
    private MappingResolver $resolver;

    /**
     * Nome dello schema Mirror sorgente.
     */
    private string $mirrorSchema;

    /**
     * Nome dello schema Master di destinazione.
     */
    private string $masterSchema;

    /**
     * ID dell'istituzione primaria.
     */
    private string $institutionId;

    /**
     * Statistiche dell'importazione.
     *
     * @var array<string, int>
     */
    private array $stats = [
        'processed' => 0,
        'success' => 0,
        'errors' => 0,
        'warnings' => 0,
    ];

    /**
     * Filtro opzionale: importa solo record con questo normativa_code (es. 'JSON').
     *
     * @var string|null
     */
    private ?string $normativaCode = null;

    /**
     * Costruttore.
     *
     * @param  string  $mirrorSchema  Nome dello schema Mirror
     * @param  string  $mappingFile  Nome del file di mapping (relativo a storage/mapping/)
     * @param  string  $institutionId  ID dell'istituzione primaria
     * @param  string|null  $normativaCode  Se impostato, importa solo record con questo normativa_code (es. 'JSON')
     */
    public function __construct(
        string $mirrorSchema,
        string $mappingFile,
        string $institutionId,
        ?string $normativaCode = null
    ) {
        $this->mirrorSchema = $mirrorSchema;
        $this->institutionId = $institutionId;
        $this->normativaCode = $normativaCode;
        $this->resolver = new MappingResolver($mappingFile);
        $this->masterSchema = $this->resolver->getConfigValue('master_schema', 'iartnet_master');
    }

    /**
     * Esegue l'importazione di tutti i record dal Mirror al Master.
     *
     * @return array<string, int>  Statistiche dell'importazione
     */
    public function importAll(): array
    {
        Log::info('MirrorToMasterImporter: Starting import', [
            'mirror_schema' => $this->mirrorSchema,
            'master_schema' => $this->masterSchema,
            'institution_id' => $this->institutionId,
            'normativa_code' => $this->normativaCode,
        ]);

        try {
            $mirrorRecordModel = MirrorRecord::forSchema($this->mirrorSchema);
            $query = $mirrorRecordModel->newQuery()->where('promoted', false);
            if ($this->normativaCode !== null && $this->normativaCode !== '') {
                $query->where('normativa_code', $this->normativaCode);
            }
            $records = $query->get();

            Log::info('MirrorToMasterImporter: Found records to import', [
                'total_records' => $records->count(),
                'filter' => 'promoted = false'.($this->normativaCode ? ", normativa_code = {$this->normativaCode}" : ''),
            ]);

            foreach ($records as $index => $record) {
                Log::debug('MirrorToMasterImporter: Processing record', [
                    'index' => $index + 1,
                    'total' => $records->count(),
                    'record_id' => $record->record_id,
                ]);

                $this->importRecord($record);
            }

            Log::info('MirrorToMasterImporter: Import completed', $this->stats);
        } catch (\Exception $e) {
            Log::error('MirrorToMasterImporter: Fatal error during import', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'stats' => $this->stats,
            ]);
            throw $e;
        }

        return $this->stats;
    }

    /**
     * Importa un singolo record dal Mirror al Master.
     *
     * @param  MirrorRecord  $mirrorRecord  Record dal Mirror
     * @return bool  true se l'importazione è riuscita, false altrimenti
     */
    public function importRecord(MirrorRecord $mirrorRecord): bool
    {
        $recordId = $mirrorRecord->record_id;

        if (empty($recordId)) {
            Log::error('MirrorToMasterImporter: Missing record_id', [
                'mirror_record' => $mirrorRecord->toArray(),
            ]);
            $this->stats['errors']++;
            return false;
        }

        $this->stats['processed']++;

        // Verifica se usare transazioni per singolo record
        $useTransaction = $this->resolver->getConfigValue('config.transaction_per_record', true);

        try {
            if ($useTransaction) {
                DB::beginTransaction();
            }

            // Carica tutte le righe KV associate al record
            $kvPairs = $this->loadKvPairs($recordId);
            
            Log::debug('MirrorToMasterImporter: Loaded KV pairs', [
                'record_id' => $recordId,
                'kv_count' => count($kvPairs),
            ]);

            // Applica il mapping
            $resolvedData = $this->resolver->resolve($kvPairs);
            
            // REGOLA: Non importiamo KV non mappati in record_kv
            // Perché non abbiamo il nome DC/EDM corrispondente
            // Solo i KV esplicitamente mappati con kv_key vengono importati
            
            Log::debug('MirrorToMasterImporter: Resolved data', [
                'record_id' => $recordId,
                'tables' => array_keys($resolvedData),
                'records_count' => count($resolvedData['records'] ?? []),
                'record_kv_count' => count($resolvedData['record_kv'] ?? []),
                'agents_count' => count($resolvedData['agents'] ?? []),
                'concepts_count' => count($resolvedData['concepts'] ?? []),
                'places_count' => count($resolvedData['places'] ?? []),
                'timespans_count' => count($resolvedData['timespans'] ?? []),
            ]);
            
            // Log dettagliato per record_kv
            if (isset($resolvedData['record_kv']) && !empty($resolvedData['record_kv'])) {
                Log::debug('MirrorToMasterImporter: record_kv data details', [
                    'record_id' => $recordId,
                    'record_kv_items' => array_map(function($item) {
                        return [
                            'source_field' => $item['source_field'] ?? null,
                            'field' => $item['field'] ?? null,
                            'value' => is_string($item['value'] ?? null) ? substr($item['value'], 0, 100) : $item['value'] ?? null,
                        ];
                    }, $resolvedData['record_kv']),
                ]);
            }

            // Costruisce struttura dati temporanea e scrive nel Master
            $this->writeToMaster($mirrorRecord, $resolvedData);

            if ($useTransaction) {
                DB::commit();
            }

            // Aggiorna il campo promoted a true dopo l'importazione riuscita
            $mirrorRecordModel = MirrorRecord::forSchema($this->mirrorSchema);
            $mirrorRecordModel->where('record_id', $recordId)->update(['promoted' => true]);

            $this->stats['success']++;
            Log::debug('MirrorToMasterImporter: Record imported successfully', [
                'record_id' => $recordId,
                'promoted_updated' => true,
            ]);

            return true;
        } catch (\Exception $e) {
            if ($useTransaction) {
                DB::rollBack();
            }

            Log::error('MirrorToMasterImporter: Error importing record', [
                'record_id' => $recordId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $this->stats['errors']++;
            return false;
        }
    }

    /**
     * Carica tutte le righe KV associate a un record_id.
     *
     * @param  string  $recordId  ID del record
     * @return array<int, array<string, mixed>>  Array di KV pairs
     */
    private function loadKvPairs(string $recordId): array
    {
        $kvModel = MirrorRecordKv::forSchema($this->mirrorSchema);
        $kvRecords = $kvModel->newQuery()
            ->where('record_id', $recordId)
            ->get();

        return $kvRecords->map(function ($kv) {
            return [
                'xpath' => $kv->xpath,
                'value_text' => $kv->value_text,
                'occurrence_idx' => $kv->occurrence_idx,
            ];
        })->toArray();
    }

    /**
     * Ottiene gli xpath mappati dai dati risolti.
     *
     * @param  array<string, array<int, array<string, mixed>>>  $resolvedData  Dati risolti
     * @return array<string>  Array di xpath mappati
     */
    private function getMappedXpaths(array $resolvedData): array
    {
        $mappedXpaths = [];
        
        foreach ($resolvedData as $tableData) {
            foreach ($tableData as $item) {
                $sourceField = $item['source_field'] ?? null;
                if ($sourceField !== null) {
                    $mappedXpaths[$sourceField] = true;
                }
            }
        }
        
        return array_keys($mappedXpaths);
    }

    /**
     * Ottiene i KV pairs non mappati.
     *
     * @param  array<int, array<string, mixed>>  $kvPairs  Tutti i KV pairs
     * @param  array<string>  $mappedXpaths  Array di xpath già mappati
     * @return array<int, array<string, mixed>>  KV pairs non mappati
     */
    private function getUnmappedKvPairs(array $kvPairs, array $mappedXpaths): array
    {
        $unmapped = [];
        $mappedXpathsSet = array_flip($mappedXpaths);
        
        foreach ($kvPairs as $kvPair) {
            $xpath = $kvPair['xpath'] ?? null;
            if ($xpath !== null && !isset($mappedXpathsSet[$xpath])) {
                $unmapped[] = $kvPair;
            }
        }
        
        return $unmapped;
    }

    /**
     * Scrive i dati risolti nelle tabelle Master.
     *
     * @param  MirrorRecord  $mirrorRecord  Record dal Mirror
     * @param  array<string, array<int, array<string, mixed>>>  $resolvedData  Dati risolti per tabella
     * @return void
     */
    private function writeToMaster(MirrorRecord $mirrorRecord, array $resolvedData): void
    {
        Log::debug('MirrorToMasterImporter: writeToMaster started', [
            'record_id' => $mirrorRecord->record_id,
        ]);

        try {
            // 1. Crea/aggiorna record principale
            $upsertOutcome = $this->upsertMasterRecord($mirrorRecord, $resolvedData);
            $masterRecordId = $upsertOutcome['id'];

            Log::debug('MirrorToMasterImporter: Master record upserted', [
                'record_id' => $mirrorRecord->record_id,
                'master_record_id' => $masterRecordId,
                'was_update' => $upsertOutcome['was_update'],
            ]);

            // 2. Gestisce entità correlate (agents, concepts, places, timespans)
            if (isset($resolvedData['agents']) && !empty($resolvedData['agents'])) {
                Log::debug('MirrorToMasterImporter: Upserting agents', [
                    'record_id' => $mirrorRecord->record_id,
                    'agents_count' => count($resolvedData['agents']),
                ]);
                $this->upsertAgents($masterRecordId, $resolvedData['agents']);
            }

            if (isset($resolvedData['concepts']) && !empty($resolvedData['concepts'])) {
                Log::debug('MirrorToMasterImporter: Upserting concepts', [
                    'record_id' => $mirrorRecord->record_id,
                    'concepts_count' => count($resolvedData['concepts']),
                ]);
                $this->upsertConcepts($masterRecordId, $resolvedData['concepts']);
            }

            if (isset($resolvedData['places']) && !empty($resolvedData['places'])) {
                Log::debug('MirrorToMasterImporter: Upserting places', [
                    'record_id' => $mirrorRecord->record_id,
                    'places_count' => count($resolvedData['places']),
                ]);
                $this->upsertPlaces($masterRecordId, $resolvedData['places']);
            }

            if (isset($resolvedData['timespans']) && !empty($resolvedData['timespans'])) {
                Log::debug('MirrorToMasterImporter: Upserting timespans', [
                    'record_id' => $mirrorRecord->record_id,
                    'timespans_count' => count($resolvedData['timespans']),
                ]);
                $this->upsertTimespans($masterRecordId, $resolvedData['timespans']);
            }

            // 3. Gestisce record_kv se presente
            if (isset($resolvedData['record_kv']) && !empty($resolvedData['record_kv'])) {
                Log::debug('MirrorToMasterImporter: Upserting record_kv', [
                    'record_id' => $mirrorRecord->record_id,
                    'record_kv_count' => count($resolvedData['record_kv']),
                    'record_kv_data' => $resolvedData['record_kv'],
                ]);
                $this->upsertRecordKv($masterRecordId, $resolvedData['record_kv']);
            } else {
                Log::debug('MirrorToMasterImporter: No record_kv data found', [
                    'record_id' => $mirrorRecord->record_id,
                    'resolved_tables' => array_keys($resolvedData),
                ]);
            }

            // 4. Gestisce altri campi in ext_json se necessario
            $this->updateExtJson($masterRecordId, $resolvedData);

            // 5. Re-import: forza is_translated = false dopo tutti gli update (anche ext_json)
            if ($upsertOutcome['was_update']) {
                $this->markMasterRecordNeedsTranslation($masterRecordId);
            }

            Log::debug('MirrorToMasterImporter: writeToMaster completed', [
                'record_id' => $mirrorRecord->record_id,
            ]);
        } catch (\Exception $e) {
            Log::error('MirrorToMasterImporter: Error in writeToMaster', [
                'record_id' => $mirrorRecord->record_id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }

    /**
     * Crea o aggiorna il record principale nella tabella records.
     *
     * @param  MirrorRecord  $mirrorRecord  Record dal Mirror
     * @param  array<string, array<int, array<string, mixed>>>  $resolvedData  Dati risolti
     * @return array{id: string, was_update: bool}  UUID del record Master e flag re-import
     */
    private function upsertMasterRecord(MirrorRecord $mirrorRecord, array $resolvedData): array
    {
        $recordsData = $resolvedData['records'] ?? [];

        // Usa sempre record_id come stable_id
        // Il record_id è determinato durante l'importazione XML → Mirror
        // ed è l'ID univoco reale della scheda (es. IDK SIRBEC)
        $stableId = $mirrorRecord->record_id;

        Log::debug('MirrorToMasterImporter: upsertMasterRecord', [
            'stable_id' => $stableId,
            'records_data_count' => count($recordsData),
        ]);

        // Verifica se il record esiste già (per stable_id)
        $existing = DB::table("{$this->masterSchema}.records")
            ->where('stable_id', $stableId)
            ->first();

        $defaultEdmType = $this->resolver->getConfigValue('config.default_record.edm_type', 'TEXT');
        $defaultPublishState = $this->resolver->getConfigValue('config.default_record.publish_state', 'draft');
        $defaultPrimaryLang = $this->resolver->getConfigValue('config.default_record.primary_lang', 'it');
        $defaultIsTranslated = $this->resolveDefaultIsTranslated();

        $recordData = [
            'stable_id' => $stableId,
            'primary_institution_id' => $this->institutionId,
            'edm_type' => $defaultEdmType,
            'publish_state' => $defaultPublishState,
            'primary_lang' => $defaultPrimaryLang,
            'is_translated' => $defaultIsTranslated,
            'updated_at' => now(),
        ];

        // Applica altri campi dai dati risolti
        foreach ($recordsData as $mapping) {
            $field = $mapping['field'] ?? null;
            $value = $mapping['value'] ?? null;
            
            if ($field === null || in_array($field, ['stable_id', 'is_translated'], true)) {
                continue;
            }
            
            // Se il campo è ext_json e il valore è un array, codificalo in JSON
            if ($field === 'ext_json') {
                if (is_array($value)) {
                    $recordData[$field] = json_encode($value, JSON_UNESCAPED_UNICODE);
                } elseif (is_string($value)) {
                    // Se è già una stringa, verifica che sia JSON valido
                    $decoded = json_decode($value, true);
                    if ($decoded !== null) {
                        // È JSON valido, usalo direttamente
                        $recordData[$field] = $value;
                    } else {
                        // Non è JSON valido, codificalo
                        $recordData[$field] = json_encode($value, JSON_UNESCAPED_UNICODE);
                    }
                } else {
                    // Valore scalare, codificalo
                    $recordData[$field] = json_encode($value, JSON_UNESCAPED_UNICODE);
                }
            } else {
                // Altri campi: assegna direttamente
                $recordData[$field] = $value;
            }
        }

        Log::debug('MirrorToMasterImporter: Record data prepared', [
            'stable_id' => $stableId,
            'is_update' => $existing !== null,
            'is_translated' => $recordData['is_translated'] ?? null,
            'fields' => array_keys($recordData),
        ]);

        if ($existing) {
            // Re-import: la scheda deve essere ritradotta indipendentemente dalla config
            $recordData['is_translated'] = false;

            DB::table("{$this->masterSchema}.records")
                ->where('id', $existing->id)
                ->update($recordData);

            Log::debug('MirrorToMasterImporter: Master record updated', [
                'stable_id' => $stableId,
                'master_record_id' => $existing->id,
                'is_translated' => false,
            ]);

            return [
                'id' => $existing->id,
                'was_update' => true,
            ];
        } else {
            // Insert
            $recordData['id'] = (string) \Illuminate\Support\Str::uuid();
            $recordData['created_at'] = now();

            DB::table("{$this->masterSchema}.records")
                ->insert($recordData);

            Log::debug('MirrorToMasterImporter: Master record inserted', [
                'stable_id' => $stableId,
                'master_record_id' => $recordData['id'],
                'is_translated' => $recordData['is_translated'],
            ]);

            return [
                'id' => $recordData['id'],
                'was_update' => false,
            ];
        }
    }

    /**
     * Legge config.default_record.is_translated gestendo bool e stringhe YAML ("false").
     */
    private function resolveDefaultIsTranslated(): bool
    {
        $value = $this->resolver->getConfigValue('config.default_record.is_translated', false);

        if (is_bool($value)) {
            return $value;
        }

        if (is_string($value)) {
            $parsed = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

            return $parsed ?? false;
        }

        return (bool) $value;
    }

    /**
     * Segna il record Master come da ritradurre dopo un re-import Mirror → Master.
     */
    private function markMasterRecordNeedsTranslation(string $masterRecordId): void
    {
        $updated = DB::table("{$this->masterSchema}.records")
            ->where('id', $masterRecordId)
            ->update([
                'is_translated' => false,
                'updated_at' => now(),
            ]);

        Log::info('MirrorToMasterImporter: Master record flagged for retranslation', [
            'master_record_id' => $masterRecordId,
            'rows_updated' => $updated,
        ]);
    }

    /**
     * Crea o aggiorna agents e le relazioni record_agents.
     *
     * @param  string  $masterRecordId  UUID del record Master
     * @param  array<int, array<string, mixed>>  $agentsData  Dati degli agents
     * @return void
     */
    private function upsertAgents(string $masterRecordId, array $agentsData): void
    {
        // Raggruppa i dati per agent (pref_label, alt_label, ext_json)
        // Il mapping può avere target_field: "ext_json" o target_field: "pref_label"/"alt_label"
        $agentsBySource = [];
        foreach ($agentsData as $data) {
            $field = $data['field'] ?? null;
            $value = trim($data['value'] ?? '');
            $sourceField = $data['source_field'] ?? null;
            
            if (empty($value)) {
                continue;
            }
            
            // Se target_field è "ext_json", distinguiamo tra pref_label e alt_label in base all'XPath ICCD:
            // - AU/AUT/AUTN → pref_label (Autore principale)
            // - AU/AUT/AUTA → alt_label (Autore alternativo)
            // - Altri (es. AU/AUT/AUTR) → dati aggiuntivi in ext_json
            if ($field === 'ext_json') {
                $label = is_string($value) ? $value : json_encode($value);
                
                // Determina se è pref_label o alt_label in base all'XPath ICCD
                $isPrefLabel = false;
                $isAltLabel = false;
                
                if ($sourceField !== null) {
                    // AU/AUT/AUTN = Autore principale (pref_label)
                    if (preg_match('/\/AUT\/AUTN$/', $sourceField)) {
                        $isPrefLabel = true;
                    }
                    // AU/AUT/AUTA = Autore alternativo (alt_label)
                    elseif (preg_match('/\/AUT\/AUTA$/', $sourceField)) {
                        $isAltLabel = true;
                    }
                }
                
                // Usa il pref_label come chiave per raggruppare gli agents
                // Se è pref_label, usa il label come chiave; se è alt_label, cerca un pref_label esistente
                $key = $label;
                if ($isAltLabel) {
                    // Per alt_label, cerca se esiste già un agent con questo valore come pref_label
                    // Se non esiste, crea una nuova entry
                    $found = false;
                    foreach ($agentsBySource as $existingKey => $existingData) {
                        if ($existingData['pref_label'] === $label) {
                            $key = $existingKey;
                            $found = true;
                            break;
                        }
                    }
                    if (!$found) {
                        // Se non esiste, usa il label come chiave (sarà trattato come pref_label se non c'è altro)
                        $key = $label;
                    }
                }
                
                if (!isset($agentsBySource[$key])) {
                    $agentsBySource[$key] = [
                        'pref_label' => null,
                        'alt_label' => null,
                        'ext_json' => [],
                        'source_fields' => [],
                    ];
                }
                
                // Assegna a pref_label o alt_label in base all'XPath
                if ($isPrefLabel) {
                    $agentsBySource[$key]['pref_label'] = $label;
                } elseif ($isAltLabel) {
                    // Se non c'è ancora un pref_label, usa questo come pref_label
                    // e metti il valore in alt_label
                    if (empty($agentsBySource[$key]['pref_label'])) {
                        $agentsBySource[$key]['pref_label'] = $label;
                    } else {
                        $agentsBySource[$key]['alt_label'] = $label;
                    }
                } else {
                    // Altri campi (es. AUTR) vanno in ext_json come dati aggiuntivi
                    $extJsonData = is_string($value) ? json_decode($value, true) : $value;
                    if (is_array($extJsonData)) {
                        $agentsBySource[$key]['ext_json'] = array_merge(
                            $agentsBySource[$key]['ext_json'],
                            $extJsonData
                        );
                    } else {
                        // Se non è un array, salvalo come valore semplice
                        $agentsBySource[$key]['ext_json'][$sourceField] = $value;
                    }
                }
                
                $agentsBySource[$key]['source_fields'][] = $sourceField;
            } elseif (in_array($field, ['pref_label', 'alt_label'])) {
                // Gestione classica con pref_label/alt_label espliciti
                if (!isset($agentsBySource[$value])) {
                    $agentsBySource[$value] = [
                        'pref_label' => null,
                        'alt_label' => null,
                        'ext_json' => [],
                        'source_fields' => [],
                    ];
                }
                
                if ($field === 'pref_label') {
                    $agentsBySource[$value]['pref_label'] = $value;
                } else {
                    $agentsBySource[$value]['alt_label'] = $value;
                }
                $agentsBySource[$value]['source_fields'][] = $sourceField;
            }
        }
        
        $ord = 0;
        foreach ($agentsBySource as $label => $agentData) {
            // Se pref_label non è impostato, usa il label come pref_label
            if (empty($agentData['pref_label'])) {
                $agentData['pref_label'] = $label;
            }
            
            // Cerca o crea agent
            $agentId = $this->findOrCreateAgent($agentData['pref_label'], $agentData);
            
            if ($agentId) {
                // Aggiorna SEMPRE ext_json dell'agent con pref_label, alt_labels e metadati di provenienza
                $this->updateAgentExtJson($agentId, $agentData);
                
                // Crea relazione record_agents
                $this->createRecordAgent($masterRecordId, $agentId, $ord);
                $ord++;
            }
        }
    }
    
    /**
     * Trova o crea un agent.
     *
     * @param  string  $label  Label dell'agent
     * @param  array<string, string|null>  $agentData  Dati dell'agent (pref_label, alt_label)
     * @return string|null  UUID dell'agent o null se errore
     */
    private function findOrCreateAgent(string $label, array $agentData): ?string
    {
        try {
            // Cerca agent esistente tramite i18n_texts
            $existing = DB::table("{$this->masterSchema}.i18n_texts")
                ->where('entity_type', 'agent')
                ->where('field_name', 'pref_label')
                ->where('text_value', $label)
                ->first();
            
            if ($existing) {
                // Verifica che l'agent esista effettivamente nella tabella agents
                $agentExists = DB::table("{$this->masterSchema}.agents")
                    ->where('id', $existing->entity_id)
                    ->exists();
                
                if ($agentExists) {
                    Log::debug('MirrorToMasterImporter: Agent found', [
                        'label' => $label,
                        'agent_id' => $existing->entity_id,
                    ]);
                    return $existing->entity_id;
                } else {
                    // L'agent non esiste più nella tabella agents, lo creiamo
                    Log::warning('MirrorToMasterImporter: Agent found in i18n_texts but not in agents table, creating new', [
                        'label' => $label,
                        'old_entity_id' => $existing->entity_id,
                    ]);
                }
            }
            
            // Crea nuovo agent
            $agentId = (string) \Illuminate\Support\Str::uuid();
            
            DB::table("{$this->masterSchema}.agents")->insert([
                'id' => $agentId,
                'ext_json' => '{}',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            
            Log::debug('MirrorToMasterImporter: Agent created', [
                'label' => $label,
                'agent_id' => $agentId,
            ]);
            
            // Crea i18n_text per pref_label
            if (!empty($agentData['pref_label'])) {
                DB::table("{$this->masterSchema}.i18n_texts")->insert([
                    'id' => (string) \Illuminate\Support\Str::uuid(),
                    'entity_type' => 'agent',
                    'entity_id' => $agentId,
                    'field_name' => 'pref_label',
                    'lang' => 'it',
                    'origin' => 'authoritative',
                    'status' => 'draft',
                    'text_value' => $agentData['pref_label'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
            
            // Crea i18n_text per alt_label se presente
            if (!empty($agentData['alt_label']) && $agentData['alt_label'] !== $agentData['pref_label']) {
                DB::table("{$this->masterSchema}.i18n_texts")->insert([
                    'id' => (string) \Illuminate\Support\Str::uuid(),
                    'entity_type' => 'agent',
                    'entity_id' => $agentId,
                    'field_name' => 'alt_label',
                    'lang' => 'it',
                    'origin' => 'authoritative',
                    'status' => 'draft',
                    'text_value' => $agentData['alt_label'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
            
            return $agentId;
        } catch (\Exception $e) {
            Log::error('MirrorToMasterImporter: Error in findOrCreateAgent', [
                'label' => $label,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }
    
    /**
     * Aggiorna ext_json dell'agent con pref_label, alt_labels e metadati di provenienza.
     *
     * Struttura ext_json:
     * {
     *   "pref_label": "Caravaggio",
     *   "alt_labels": ["Michelangelo Merisi"],
     *   "source": {
     *     "standard": "ICCD",
     *     "xpath": "AU/AUT/AUTN"
     *   },
     *   ...altri dati dal mapping...
     * }
     *
     * @param  string  $agentId  UUID dell'agent
     * @param  array<string, mixed>  $agentData  Dati dell'agent
     * @return void
     */
    private function updateAgentExtJson(string $agentId, array $agentData): void
    {
        try {
            $currentExtJson = DB::table("{$this->masterSchema}.agents")
                ->where('id', $agentId)
                ->value('ext_json');

            $currentData = is_string($currentExtJson)
                ? (json_decode($currentExtJson, true) ?? [])
                : ($currentExtJson ?? []);

            if (!is_array($currentData)) {
                $currentData = [];
            }

            // Prepara alt_labels come array
            $altLabels = [];
            if (!empty($agentData['alt_label']) && $agentData['alt_label'] !== $agentData['pref_label']) {
                $altLabels = [$agentData['alt_label']];
            }
            
            // Se ci sono già alt_labels in currentData, preservali e aggiungi i nuovi
            if (isset($currentData['alt_labels']) && is_array($currentData['alt_labels'])) {
                $altLabels = array_unique(array_merge($currentData['alt_labels'], $altLabels));
            }

            // Prepara metadati di provenienza nella struttura richiesta
            $sourceFields = array_unique($agentData['source_fields'] ?? []);
            $sourceXpath = !empty($sourceFields) ? $sourceFields[0] : null; // Prendi il primo xpath come principale
            
            $source = [
                'standard' => 'ICCD',
                'xpath' => $sourceXpath,
            ];
            
            // Se ci sono più xpath, aggiungili come array
            if (count($sourceFields) > 1) {
                $source['xpaths'] = $sourceFields;
            }

            // Costruisci la struttura ext_json completa
            $extJsonData = [
                'pref_label' => $agentData['pref_label'] ?? null,
                'alt_labels' => array_values($altLabels), // array_values per rimuovere chiavi numeriche
                'source' => $source,
            ];

            // Merge con dati ext_json esistenti (preserva altri campi)
            // I campi principali (pref_label, alt_labels, source) hanno priorità
            $mergedData = array_merge(
                $currentData,
                $agentData['ext_json'] ?? [], // Dati aggiuntivi dal mapping
                $extJsonData // Struttura principale (sovrascrive se presente)
            );

            DB::table("{$this->masterSchema}.agents")
                ->where('id', $agentId)
                ->update([
                    'ext_json' => json_encode($mergedData, JSON_UNESCAPED_UNICODE),
                    'updated_at' => now(),
                ]);

            Log::debug('MirrorToMasterImporter: Agent ext_json updated', [
                'agent_id' => $agentId,
                'pref_label' => $extJsonData['pref_label'],
                'alt_labels_count' => count($extJsonData['alt_labels']),
                'source_xpath' => $sourceXpath,
            ]);
        } catch (\Exception $e) {
            Log::error('MirrorToMasterImporter: Error updating agent ext_json', [
                'agent_id' => $agentId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    /**
     * Crea relazione record_agents.
     *
     * @param  string  $masterRecordId  UUID del record Master
     * @param  string  $agentId  UUID dell'agent
     * @param  int  $ord  Ordine
     * @return void
     */
    private function createRecordAgent(string $masterRecordId, string $agentId, int $ord): void
    {
        try {
            // Verifica se esiste già
            $existing = DB::table("{$this->masterSchema}.record_agents")
                ->where('record_id', $masterRecordId)
                ->where('agent_id', $agentId)
                ->where('role', 'creator')
                ->first();
            
            if (!$existing) {
                DB::table("{$this->masterSchema}.record_agents")->insert([
                    'record_id' => $masterRecordId,
                    'agent_id' => $agentId,
                    'role' => 'creator',
                    'ord' => $ord,
                    'origin' => 'authoritative',
                ]);
                
                Log::debug('MirrorToMasterImporter: Record-Agent relation created', [
                    'master_record_id' => $masterRecordId,
                    'agent_id' => $agentId,
                    'ord' => $ord,
                ]);
            }
        } catch (\Exception $e) {
            Log::error('MirrorToMasterImporter: Error in createRecordAgent', [
                'master_record_id' => $masterRecordId,
                'agent_id' => $agentId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }

    /**
     * Crea o aggiorna concepts e le relazioni record_concepts.
     *
     * @param  string  $masterRecordId  UUID del record Master
     * @param  array<int, array<string, mixed>>  $conceptsData  Dati dei concepts
     * @return void
     */
    private function upsertConcepts(string $masterRecordId, array $conceptsData): void
    {
        foreach ($conceptsData as $data) {
            $field = $data['field'] ?? null;
            $value = trim($data['value'] ?? '');
            
            if (empty($value) || $field !== 'pref_label') {
                continue;
            }
            
            // Cerca o crea concept
            $conceptId = $this->findOrCreateConcept($value);
            
            if ($conceptId) {
                // Crea relazione record_concepts
                $this->createRecordConcept($masterRecordId, $conceptId);
            }
        }
    }
    
    /**
     * Trova o crea un concept.
     *
     * @param  string  $label  Label del concept
     * @return string|null  UUID del concept o null se errore
     */
    private function findOrCreateConcept(string $label): ?string
    {
        // Cerca concept esistente tramite i18n_texts
        $existing = DB::table("{$this->masterSchema}.i18n_texts")
            ->where('entity_type', 'concept')
            ->where('field_name', 'pref_label')
            ->where('text_value', $label)
            ->first();
        
        if ($existing) {
            // Verifica che il concept esista effettivamente nella tabella concepts
            $conceptExists = DB::table("{$this->masterSchema}.concepts")
                ->where('id', $existing->entity_id)
                ->exists();
            
            if ($conceptExists) {
                return $existing->entity_id;
            } else {
                // Il concept non esiste più nella tabella concepts, lo creiamo
                Log::warning('MirrorToMasterImporter: Concept found in i18n_texts but not in concepts table, creating new', [
                    'label' => $label,
                    'old_entity_id' => $existing->entity_id,
                ]);
            }
        }
        
        // Crea nuovo concept
        $conceptId = (string) \Illuminate\Support\Str::uuid();
        
        DB::table("{$this->masterSchema}.concepts")->insert([
            'id' => $conceptId,
            'ext_json' => '{}',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        
        // Crea i18n_text per pref_label
        DB::table("{$this->masterSchema}.i18n_texts")->insert([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'entity_type' => 'concept',
            'entity_id' => $conceptId,
            'field_name' => 'pref_label',
            'lang' => 'it',
            'origin' => 'authoritative',
            'status' => 'draft',
            'text_value' => $label,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        
        return $conceptId;
    }
    
    /**
     * Crea relazione record_concepts.
     *
     * @param  string  $masterRecordId  UUID del record Master
     * @param  string  $conceptId  UUID del concept
     * @return void
     */
    private function createRecordConcept(string $masterRecordId, string $conceptId): void
    {
        // Verifica se esiste già
        $existing = DB::table("{$this->masterSchema}.record_concepts")
            ->where('record_id', $masterRecordId)
            ->where('concept_id', $conceptId)
            ->where('role', 'subject')
            ->first();
        
        if (!$existing) {
            DB::table("{$this->masterSchema}.record_concepts")->insert([
                'record_id' => $masterRecordId,
                'concept_id' => $conceptId,
                'role' => 'subject',
                'origin' => 'authoritative',
            ]);
        }
    }

    /**
     * Crea o aggiorna places e le relazioni record_places.
     *
     * @param  string  $masterRecordId  UUID del record Master
     * @param  array<int, array<string, mixed>>  $placesData  Dati dei places
     * @return void
     */
    private function upsertPlaces(string $masterRecordId, array $placesData): void
    {
        // Raggruppa i dati per place
        // Il mapping può avere target_field: "ext_json" o target_field: "pref_label"
        $placesBySource = [];
        foreach ($placesData as $data) {
            $field = $data['field'] ?? null;
            $value = trim($data['value'] ?? '');
            $sourceField = $data['source_field'] ?? null;
            
            if (empty($value)) {
                continue;
            }
            
            // Se target_field è "ext_json", il valore va usato come pref_label
            if ($field === 'ext_json') {
                $label = is_string($value) ? $value : json_encode($value);
                if (!isset($placesBySource[$label])) {
                    $placesBySource[$label] = [
                        'pref_label' => $label,
                        'ext_json' => [],
                        'source_fields' => [],
                    ];
                }
                // Salva i dati in ext_json
                $extJsonData = is_string($value) ? json_decode($value, true) : $value;
                if (is_array($extJsonData)) {
                    $placesBySource[$label]['ext_json'] = array_merge(
                        $placesBySource[$label]['ext_json'],
                        $extJsonData
                    );
                }
                $placesBySource[$label]['source_fields'][] = $sourceField;
            } elseif ($field === 'pref_label') {
                // Gestione classica con pref_label esplicito
                if (!isset($placesBySource[$value])) {
                    $placesBySource[$value] = [
                        'pref_label' => $value,
                        'ext_json' => [],
                        'source_fields' => [],
                    ];
                }
                $placesBySource[$value]['source_fields'][] = $sourceField;
            }
        }
        
        foreach ($placesBySource as $label => $placeData) {
            // Se pref_label non è impostato, usa il label come pref_label
            if (empty($placeData['pref_label'])) {
                $placeData['pref_label'] = $label;
            }
            
            // Cerca o crea place
            $placeId = $this->findOrCreatePlace($placeData['pref_label']);
            
            if ($placeId) {
                // Aggiorna ext_json del place con metadati di provenienza
                if (!empty($placeData['ext_json']) || !empty($placeData['source_fields'])) {
                    $this->updatePlaceExtJson($placeId, $placeData);
                }
                
                // Crea relazione record_places
                $this->createRecordPlace($masterRecordId, $placeId);
            }
        }
    }
    
    /**
     * Trova o crea un place.
     *
     * @param  string  $label  Label del place
     * @return string|null  UUID del place o null se errore
     */
    private function findOrCreatePlace(string $label): ?string
    {
        // Cerca place esistente tramite i18n_texts
        $existing = DB::table("{$this->masterSchema}.i18n_texts")
            ->where('entity_type', 'place')
            ->where('field_name', 'pref_label')
            ->where('text_value', $label)
            ->first();
        
        if ($existing) {
            // Verifica che il place esista effettivamente nella tabella places
            $placeExists = DB::table("{$this->masterSchema}.places")
                ->where('id', $existing->entity_id)
                ->exists();
            
            if ($placeExists) {
                return $existing->entity_id;
            } else {
                // Il place non esiste più nella tabella places, lo creiamo
                Log::warning('MirrorToMasterImporter: Place found in i18n_texts but not in places table, creating new', [
                    'label' => $label,
                    'old_entity_id' => $existing->entity_id,
                ]);
            }
        }
        
        // Crea nuovo place
        $placeId = (string) \Illuminate\Support\Str::uuid();
        
        DB::table("{$this->masterSchema}.places")->insert([
            'id' => $placeId,
            'ext_json' => '{}',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        
        // Crea i18n_text per pref_label
        DB::table("{$this->masterSchema}.i18n_texts")->insert([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'entity_type' => 'place',
            'entity_id' => $placeId,
            'field_name' => 'pref_label',
            'lang' => 'it',
            'origin' => 'authoritative',
            'status' => 'draft',
            'text_value' => $label,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        
        return $placeId;
    }
    
    /**
     * Aggiorna ext_json del place con metadati di provenienza.
     *
     * @param  string  $placeId  UUID del place
     * @param  array<string, mixed>  $placeData  Dati del place
     * @return void
     */
    private function updatePlaceExtJson(string $placeId, array $placeData): void
    {
        try {
            $currentExtJson = DB::table("{$this->masterSchema}.places")
                ->where('id', $placeId)
                ->value('ext_json');

            $currentData = is_string($currentExtJson)
                ? (json_decode($currentExtJson, true) ?? [])
                : ($currentExtJson ?? []);

            if (!is_array($currentData)) {
                $currentData = [];
            }

            // Aggiungi metadati di provenienza
            $metadata = [
                'source_standard' => 'ICCD',
                'source_format' => 'XML',
                'import_process' => 'ICCD_to_DC',
                'source_fields' => array_unique($placeData['source_fields'] ?? []),
            ];

            // Merge con dati ext_json esistenti
            $mergedData = array_merge($currentData, $placeData['ext_json'] ?? [], $metadata);

            DB::table("{$this->masterSchema}.places")
                ->where('id', $placeId)
                ->update([
                    'ext_json' => json_encode($mergedData),
                    'updated_at' => now(),
                ]);

            Log::debug('MirrorToMasterImporter: Place ext_json updated', [
                'place_id' => $placeId,
                'source_fields' => $metadata['source_fields'],
            ]);
        } catch (\Exception $e) {
            Log::error('MirrorToMasterImporter: Error updating place ext_json', [
                'place_id' => $placeId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Crea relazione record_places.
     *
     * @param  string  $masterRecordId  UUID del record Master
     * @param  string  $placeId  UUID del place
     * @return void
     */
    private function createRecordPlace(string $masterRecordId, string $placeId): void
    {
        // Verifica se esiste già
        $existing = DB::table("{$this->masterSchema}.record_places")
            ->where('record_id', $masterRecordId)
            ->where('place_id', $placeId)
            ->where('role', 'spatial')
            ->first();
        
        if (!$existing) {
            DB::table("{$this->masterSchema}.record_places")->insert([
                'record_id' => $masterRecordId,
                'place_id' => $placeId,
                'role' => 'spatial',
                'origin' => 'authoritative',
            ]);
        }
    }

    /**
     * Crea o aggiorna timespans e le relazioni record_timespans.
     *
     * @param  string  $masterRecordId  UUID del record Master
     * @param  array<int, array<string, mixed>>  $timespansData  Dati dei timespans
     * @return void
     */
    private function upsertTimespans(string $masterRecordId, array $timespansData): void
    {
        // Raggruppa i dati per timespan (begin e end)
        // Il mapping può avere target_field: "begin_date" o "end_date"
        $timespanData = [
            'begin' => null,
            'end' => null,
            'ext_json' => [],
            'source_fields' => [],
        ];
        
        foreach ($timespansData as $data) {
            $field = $data['field'] ?? null;
            $value = trim($data['value'] ?? '');
            $sourceField = $data['source_field'] ?? null;
            
            if (empty($value)) {
                continue;
            }
            
            // Il mapping può avere target_field: "begin_date" o "end_date"
            // Mappiamo a "begin" e "end" per la logica interna
            if ($field === 'begin_date') {
                $timespanData['begin'] = $value;
                $timespanData['source_fields'][] = $sourceField;
            } elseif ($field === 'end_date') {
                $timespanData['end'] = $value;
                $timespanData['source_fields'][] = $sourceField;
            } elseif (in_array($field, ['begin', 'end'])) {
                // Gestione classica con begin/end espliciti
                $timespanData[$field] = $value;
                $timespanData['source_fields'][] = $sourceField;
            } elseif ($field === 'ext_json') {
                // Se target_field è "ext_json", salva i dati in ext_json
                $extJsonData = is_string($value) ? json_decode($value, true) : $value;
                if (is_array($extJsonData)) {
                    $timespanData['ext_json'] = array_merge(
                        $timespanData['ext_json'],
                        $extJsonData
                    );
                }
                $timespanData['source_fields'][] = $sourceField;
            }
        }
        
        // Crea timespan solo se c'è almeno begin o end
        if ($timespanData['begin'] || $timespanData['end']) {
            $timespanId = $this->createTimespan($timespanData);
            
            if ($timespanId) {
                // Aggiorna ext_json del timespan con metadati di provenienza
                if (!empty($timespanData['ext_json']) || !empty($timespanData['source_fields'])) {
                    $this->updateTimespanExtJson($timespanId, $timespanData);
                }
                
                // Crea relazione record_timespans
                $this->createRecordTimespan($masterRecordId, $timespanId);
            }
        }
    }
    
    /**
     * Crea un timespan.
     *
     * @param  array<string, string|null>  $timespanData  Dati del timespan (begin, end)
     * @return string|null  UUID del timespan o null se errore
     */
    private function createTimespan(array $timespanData): ?string
    {
        $timespanId = (string) \Illuminate\Support\Str::uuid();
        
        // Converte le date in formato date (YYYY-MM-DD)
        $beginDate = $this->parseDate($timespanData['begin']);
        $endDate = $this->parseDate($timespanData['end']);
        
        // VALIDAZIONE: begin_date deve essere <= end_date (constraint CHECK del DB)
        if ($beginDate !== null && $endDate !== null) {
            if ($beginDate > $endDate) {
                // Le date sono invertite, scambiamole e loggiamo un warning
                Log::warning('MirrorToMasterImporter: Timespan dates are inverted, swapping them', [
                    'original_begin' => $beginDate,
                    'original_end' => $endDate,
                    'source_fields' => $timespanData['source_fields'] ?? [],
                ]);
                
                // Scambia le date
                $temp = $beginDate;
                $beginDate = $endDate;
                $endDate = $temp;
            }
        }
        
        try {
            DB::table("{$this->masterSchema}.timespans")->insert([
                'id' => $timespanId,
                'begin_date' => $beginDate,
                'end_date' => $endDate,
                'ext_json' => '{}',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            
            Log::debug('MirrorToMasterImporter: Timespan created', [
                'timespan_id' => $timespanId,
                'begin_date' => $beginDate,
                'end_date' => $endDate,
            ]);
            
            return $timespanId;
        } catch (\Exception $e) {
            Log::error('MirrorToMasterImporter: Error creating timespan', [
                'begin_date' => $beginDate,
                'end_date' => $endDate,
                'error' => $e->getMessage(),
                'source_fields' => $timespanData['source_fields'] ?? [],
            ]);
            return null;
        }
    }
    
    /**
     * Converte una stringa data in formato date PostgreSQL.
     *
     * Gestisce vari formati di date ICCD:
     * - YYYY-MM-DD (ISO)
     * - DD/MM/YYYY
     * - YYYY
     * - YYYY-MM
     * - Date con caratteri speciali o spazi
     *
     * @param  string|null  $dateString  Stringa data
     * @return string|null  Data in formato YYYY-MM-DD o null se non valida
     */
    private function parseDate(?string $dateString): ?string
    {
        if (empty($dateString)) {
            return null;
        }
        
        // Pulisci la stringa: rimuovi spazi extra e caratteri non necessari
        $cleaned = trim($dateString);
        
        // Se è già in formato YYYY-MM-DD, verifica che sia valida
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $cleaned, $matches)) {
            $year = (int) $matches[1];
            $month = (int) $matches[2];
            $day = (int) $matches[3];
            
            // Validazione base: anno ragionevole (1000-2100), mese 1-12, giorno 1-31
            if ($year >= 1000 && $year <= 2100 && $month >= 1 && $month <= 12 && $day >= 1 && $day <= 31) {
                if (checkdate($month, $day, $year)) {
                    return $cleaned;
                }
            }
        }
        
        // Se è solo un anno (YYYY)
        if (preg_match('/^(\d{4})$/', $cleaned, $matches)) {
            $year = (int) $matches[1];
            if ($year >= 1000 && $year <= 2100) {
                return sprintf('%04d-01-01', $year); // Primo gennaio dell'anno
            }
        }
        
        // Se è YYYY-MM
        if (preg_match('/^(\d{4})-(\d{2})$/', $cleaned, $matches)) {
            $year = (int) $matches[1];
            $month = (int) $matches[2];
            if ($year >= 1000 && $year <= 2100 && $month >= 1 && $month <= 12) {
                return sprintf('%04d-%02d-01', $year, $month); // Primo giorno del mese
            }
        }
        
        // Prova con strtotime per formati comuni (DD/MM/YYYY, etc.)
        $timestamp = strtotime($cleaned);
        if ($timestamp !== false) {
            $parsed = date('Y-m-d', $timestamp);
            // Verifica che la data parsata sia ragionevole (non nel futuro lontano o passato remoto)
            $year = (int) date('Y', $timestamp);
            if ($year >= 1000 && $year <= 2100) {
                return $parsed;
            }
        }
        
        // Se non riusciamo a parsare, loggiamo un warning e restituiamo null
        Log::warning('MirrorToMasterImporter: Unable to parse date', [
            'date_string' => $dateString,
            'cleaned' => $cleaned,
        ]);
        
        return null;
    }
    
    /**
     * Aggiorna ext_json del timespan con metadati di provenienza.
     *
     * @param  string  $timespanId  UUID del timespan
     * @param  array<string, mixed>  $timespanData  Dati del timespan
     * @return void
     */
    private function updateTimespanExtJson(string $timespanId, array $timespanData): void
    {
        try {
            $currentExtJson = DB::table("{$this->masterSchema}.timespans")
                ->where('id', $timespanId)
                ->value('ext_json');

            $currentData = is_string($currentExtJson)
                ? (json_decode($currentExtJson, true) ?? [])
                : ($currentExtJson ?? []);

            if (!is_array($currentData)) {
                $currentData = [];
            }

            // Aggiungi metadati di provenienza
            $metadata = [
                'source_standard' => 'ICCD',
                'source_format' => 'XML',
                'import_process' => 'ICCD_to_DC',
                'source_fields' => array_unique($timespanData['source_fields'] ?? []),
            ];

            // Merge con dati ext_json esistenti
            $mergedData = array_merge($currentData, $timespanData['ext_json'] ?? [], $metadata);

            DB::table("{$this->masterSchema}.timespans")
                ->where('id', $timespanId)
                ->update([
                    'ext_json' => json_encode($mergedData),
                    'updated_at' => now(),
                ]);

            Log::debug('MirrorToMasterImporter: Timespan ext_json updated', [
                'timespan_id' => $timespanId,
                'source_fields' => $metadata['source_fields'],
            ]);
        } catch (\Exception $e) {
            Log::error('MirrorToMasterImporter: Error updating timespan ext_json', [
                'timespan_id' => $timespanId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Crea relazione record_timespans.
     *
     * @param  string  $masterRecordId  UUID del record Master
     * @param  string  $timespanId  UUID del timespan
     * @return void
     */
    private function createRecordTimespan(string $masterRecordId, string $timespanId): void
    {
        // Verifica se esiste già
        $existing = DB::table("{$this->masterSchema}.record_timespans")
            ->where('record_id', $masterRecordId)
            ->where('timespan_id', $timespanId)
            ->where('role', 'temporal')
            ->first();
        
        if (!$existing) {
            DB::table("{$this->masterSchema}.record_timespans")->insert([
                'record_id' => $masterRecordId,
                'timespan_id' => $timespanId,
                'role' => 'temporal',
                'origin' => 'authoritative',
            ]);
        }
    }

    /**
     * Crea o aggiorna record_kv nel Master.
     *
     * @param  string  $masterRecordId  UUID del record Master
     * @param  array<int, array<string, mixed>>  $recordKvData  Dati per record_kv
     * @return void
     */
    private function upsertRecordKv(string $masterRecordId, array $recordKvData): void
    {
        try {
            // Chiavi (kv_key + lang) da sincronizzare su i18n_texts dopo il loop, così con multiple=true
            // si usa il valore finale (eventualmente concatenato) da record_kv invece del singolo item
            $i18nKeysToSync = [];

            foreach ($recordKvData as $kvData) {
                $field = $kvData['field'] ?? null;
                $value = $kvData['value'] ?? null;
                $sourceField = $kvData['source_field'] ?? null;
                $mappingRule = $kvData['mapping_rule'] ?? [];
                $kvKey = $kvData['kv_key'] ?? null;

                if ($value === null || $sourceField === null) {
                    Log::warning('MirrorToMasterImporter: Invalid record_kv data', [
                        'master_record_id' => $masterRecordId,
                        'kv_data' => $kvData,
                    ]);
                    continue;
                }

                // REGOLA FONDAMENTALE: record_kv.key deve contenere ESCLUSIVAMENTE il nome DC/EDM
                // Se kv_key non è definito nel mapping, non possiamo importare (non abbiamo il nome DC)
                if (empty($kvKey)) {
                    // Se target_field è un nome DC valido (non "ext_json" o "value_text"), usalo come fallback
                    if ($field !== null && $field !== 'ext_json' && $field !== 'value_text') {
                        $kvKey = $field;
                    } else {
                        Log::warning('MirrorToMasterImporter: Missing kv_key for record_kv mapping, skipping', [
                            'master_record_id' => $masterRecordId,
                            'source_field' => $sourceField,
                            'target_field' => $field,
                        ]);
                        continue;
                    }
                }

                // Prepara ext_json con metadati di provenienza ICCD/SBN
                // Legge il campo 'ext' dal mapping rule se presente, altrimenti usa valori di default
                $extFromMapping = $mappingRule['ext'] ?? [];
                $extJsonMetadata = [
                    'source_standard' => $extFromMapping['standard'] ?? 'ICCD',
                    'source_xpath' => $extFromMapping['source_xpath'] ?? $sourceField,
                    'source_format' => 'XML',
                    'import_process' => 'ICCD_to_DC',
                ];

                // Se field è 'ext_json', aggiungi anche il valore processato
                if ($field === 'ext_json') {
                    $extJsonValue = $value;
                    if (is_string($value)) {
                        $decoded = json_decode($value, true);
                        if ($decoded !== null) {
                            $extJsonValue = $decoded;
                        }
                    }
                    $extJsonMetadata = array_merge($extJsonMetadata, $extJsonValue);
                }

                // Crea record_kv: multiple dal mapping (sbn-to-master / iccd-to-master)
                // Con multiple=true i valori vengono concatenati in createRecordKvEntry
                $isMultiple = $mappingRule['multiple'] ?? false;
                $valueText = is_string($value) ? $value : json_encode($value);
                $this->createRecordKvEntry(
                    $masterRecordId,
                    (string) $kvKey,
                    'string',
                    $valueText,
                    null,
                    null,
                    $extJsonMetadata,
                    $isMultiple
                );

                // Registra (kv_key, lang) per sincronizzare i18n_texts DOPO il loop con il valore finale da record_kv
                // così con multiple=true i18n_texts riceve il testo concatenato come in record_kv
                if ($field === 'value_text' && ! empty($kvKey) && ! empty($value)) {
                    $transform = $mappingRule['transform'] ?? null;
                    $lang = $mappingRule['lang'] ?? 'it';
                    if ($transform !== 'json_merge' && $field !== 'ext_json') {
                        $i18nKeysToSync[(string) $kvKey . '|' . $lang] = ['key' => (string) $kvKey, 'lang' => $lang];
                    }
                }
            }

            // Sincronizza i18n_texts con il valore finale da record_kv (corretto per multiple=true)
            foreach ($i18nKeysToSync as $entry) {
                $kvKeyStr = $entry['key'];
                $lang = $entry['lang'];
                $row = DB::table("{$this->masterSchema}.record_kv")
                    ->where('record_id', $masterRecordId)
                    ->where('key', $kvKeyStr)
                    ->first();
                if ($row !== null && isset($row->value_text) && (string) $row->value_text !== '') {
                    $this->insertI18nText('record', $masterRecordId, $kvKeyStr, $lang, (string) $row->value_text);
                }
            }
        } catch (\Exception $e) {
            Log::error('MirrorToMasterImporter: Error in upsertRecordKv', [
                'master_record_id' => $masterRecordId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }

    /**
     * Crea una entry in record_kv.
     *
     * REGOLE FONDAMENTALI:
     * - $key deve essere ESCLUSIVAMENTE un nome DC/EDM (es: title, creator, subject, etc.)
     * - MAI usare XPath ICCD come $key
     * - Gli XPath ICCD vanno in $extJsonValue['source_xpath']
     * - Il valore semantico va in $valueText
     *
     * @param  string  $masterRecordId  UUID del record Master
     * @param  string  $key  Chiave DC/EDM (es: "title", "creator", "subject", etc.) - MAI XPath ICCD
     * @param  string  $datatype  Tipo di dato (string, json, uri, etc.)
     * @param  string|null  $valueText  Valore semantico del dato
     * @param  string|null  $valueUri  Valore URI
     * @param  mixed  $valueJson  Valore JSON (non usato se datatype non è 'json')
     * @param  mixed  $extJsonValue  Metadati di provenienza (deve contenere source_xpath, source_standard, etc.)
     * @param  bool  $multiple  Se true, permette più record con stessa key (cerca anche per value_text)
     * @return void
     */
    private function createRecordKvEntry(
        string $masterRecordId,
        string $key,
        string $datatype,
        ?string $valueText,
        ?string $valueUri,
        mixed $valueJson,
        mixed $extJsonValue,
        bool $multiple = false
    ): void {
        // Verifica se esiste già un record_kv con la stessa key
        // NOTA: Il vincolo univoco uq_record_kv_key su (record_id, key) impedisce
        // di avere più record con la stessa key, anche con multiple: true
        $existing = DB::table("{$this->masterSchema}.record_kv")
            ->where('record_id', $masterRecordId)
            ->where('key', $key)
            ->first();
        
        // Gestione valori multipli
        $existingValueText = $existing->value_text ?? null;
        
        // Se multiple = true e esiste già un record, concateniamo i valori
        if ($multiple && $existing !== null && $valueText !== null) {
            if ($existingValueText !== null && $existingValueText !== $valueText) {
                // Valore diverso: concateniamo i valori separati da " | "
                // Verifica che il nuovo valore non sia già presente
                $values = explode(' | ', $existingValueText);
                if (!in_array($valueText, $values, true)) {
                    $valueText = trim($existingValueText . ' | ' . $valueText);
                } else {
                    // Valore già presente, non aggiungiamo
                    $valueText = $existingValueText;
                }
            }
        }
        
        $kvId = $existing ? $existing->id : (string) \Illuminate\Support\Str::uuid();
        
        // Prepara ext_json con merge se esiste già un record
        $extJson = '{}';
        if (!empty($extJsonValue)) {
            $extJsonData = is_array($extJsonValue) ? $extJsonValue : [];
            
            // Se esiste già un record, facciamo merge con ext_json esistente
            if ($existing !== null) {
                $existingExtJson = $existing->ext_json ?? '{}';
                $existingExtJsonData = is_string($existingExtJson) 
                    ? (json_decode($existingExtJson, true) ?? [])
                    : ($existingExtJson ?? []);
                
                if (is_array($existingExtJsonData)) {
                    // Merge: preserva source_xpath esistente e aggiunge il nuovo se diverso
                    $existingXpath = $existingExtJsonData['source_xpath'] ?? null;
                    $newXpath = $extJsonData['source_xpath'] ?? null;
                    
                    if ($existingXpath !== null && $newXpath !== null && $existingXpath !== $newXpath) {
                        // Se ci sono xpath diversi, creiamo un array
                        $xpaths = is_array($existingXpath) ? $existingXpath : [$existingXpath];
                        if (!in_array($newXpath, $xpaths, true)) {
                            $xpaths[] = $newXpath;
                        }
                        $extJsonData['source_xpath'] = count($xpaths) === 1 ? $xpaths[0] : $xpaths;
                    } elseif ($existingXpath === null && $newXpath !== null) {
                        $extJsonData['source_xpath'] = $newXpath;
                    } elseif ($existingXpath !== null) {
                        $extJsonData['source_xpath'] = $existingXpath;
                    }
                    
                    // Merge con dati esistenti (preserva altri campi)
                    $extJsonData = array_merge($existingExtJsonData, $extJsonData);
                }
            }
            
            $extJson = json_encode($extJsonData, JSON_UNESCAPED_UNICODE);
        } elseif ($existing !== null) {
            // Se non c'è nuovo extJsonValue ma esiste un record, preserva quello esistente
            $extJson = is_string($existing->ext_json) ? $existing->ext_json : json_encode($existing->ext_json ?? [], JSON_UNESCAPED_UNICODE);
        }

        $kvData = [
            'id' => $kvId,
            'record_id' => $masterRecordId,
            'key' => $key,
            'datatype' => $datatype,
            'value_text' => $valueText,
            'value_uri' => $valueUri,
            'value_json' => $datatype === 'json' && $valueJson !== null ? json_encode($valueJson) : null,
            'display_order' => 0,
            'origin' => 'authoritative',
            'ext_json' => $extJson,
            'updated_at' => now(),
        ];

        if ($existing) {
            // Update (non aggiorniamo id e created_at)
            unset($kvData['id']);
            DB::table("{$this->masterSchema}.record_kv")
                ->where('id', $existing->id)
                ->update($kvData);
            
            Log::debug('MirrorToMasterImporter: Record_kv updated', [
                'master_record_id' => $masterRecordId,
                'key' => $key,
                'kv_id' => $existing->id,
            ]);
        } else {
            // Insert
            $kvData['created_at'] = now();
            DB::table("{$this->masterSchema}.record_kv")
                ->insert($kvData);
            
            Log::debug('MirrorToMasterImporter: Record_kv inserted', [
                'master_record_id' => $masterRecordId,
                'key' => $key,
                'kv_id' => $kvId,
            ]);
        }
    }

    /**
     * Inserisce o aggiorna una entry in i18n_texts per un'entità record.
     *
     * REGOLE FONDAMENTALI:
     * - entity_type = 'record' per tutti i campi DC/EDM testuali
     * - field_name = nome DC/EDM (es: "title", "description", "type")
     * - text_value = valore semantico del campo
     * - origin = 'authoritative' (dati provenienti da importazione)
     * - status = 'draft' (stato iniziale)
     *
     * Questa funzione garantisce che i campi DC/EDM testuali siano disponibili
     * sia in record_kv (per tracciabilità ICCD) che in i18n_texts (per View DC/EDM).
     *
     * @param  string  $entityType  Tipo di entità ('record', 'agent', 'place', 'timespan')
     * @param  string  $entityId  UUID dell'entità
     * @param  string  $fieldName  Nome del campo DC/EDM (es: "title", "description")
     * @param  string  $lang  Codice lingua (es: "it", "en")
     * @param  string  $textValue  Valore testuale del campo
     * @return void
     */
    private function insertI18nText(
        string $entityType,
        string $entityId,
        string $fieldName,
        string $lang,
        string $textValue
    ): void {
        try {
            // Verifica se esiste già un record i18n_texts con gli stessi parametri
            $existing = DB::table("{$this->masterSchema}.i18n_texts")
                ->where('entity_type', $entityType)
                ->where('entity_id', $entityId)
                ->where('field_name', $fieldName)
                ->where('lang', $lang)
                ->first();

            $i18nData = [
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'field_name' => $fieldName,
                'lang' => $lang,
                'text_value' => $textValue,
                'origin' => 'authoritative',
                'status' => 'draft',
                'updated_at' => now(),
            ];

            if ($existing) {
                // Update (non aggiorniamo id e created_at)
                DB::table("{$this->masterSchema}.i18n_texts")
                    ->where('id', $existing->id)
                    ->update($i18nData);

                Log::debug('MirrorToMasterImporter: i18n_texts updated', [
                    'entity_type' => $entityType,
                    'entity_id' => $entityId,
                    'field_name' => $fieldName,
                    'lang' => $lang,
                    'i18n_id' => $existing->id,
                ]);
            } else {
                // Insert
                $i18nData['id'] = (string) \Illuminate\Support\Str::uuid();
                $i18nData['created_at'] = now();
                DB::table("{$this->masterSchema}.i18n_texts")
                    ->insert($i18nData);

                Log::debug('MirrorToMasterImporter: i18n_texts inserted', [
                    'entity_type' => $entityType,
                    'entity_id' => $entityId,
                    'field_name' => $fieldName,
                    'lang' => $lang,
                    'i18n_id' => $i18nData['id'],
                ]);
            }
        } catch (\Exception $e) {
            Log::error('MirrorToMasterImporter: Error in insertI18nText', [
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'field_name' => $fieldName,
                'lang' => $lang,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            // Non blocchiamo l'importazione se fallisce l'inserimento in i18n_texts
            // ma logghiamo l'errore per debug
        }
    }

    /**
     * Aggiorna il campo ext_json del record Master con dati aggiuntivi.
     *
     * @param  string  $masterRecordId  UUID del record Master
     * @param  array<string, array<int, array<string, mixed>>>  $resolvedData  Dati risolti
     * @return void
     */
    private function updateExtJson(string $masterRecordId, array $resolvedData): void
    {
        // Gestisce i campi ext_json per la tabella records
        $recordsData = $resolvedData['records'] ?? [];
        $extJsonData = [];

        foreach ($recordsData as $mapping) {
            if ($mapping['field'] === 'ext_json') {
                $value = $mapping['value'] ?? null;
                if ($value !== null) {
                    // Se il valore è una stringa JSON, decodificalo
                    if (is_string($value)) {
                        $decoded = json_decode($value, true);
                        if ($decoded !== null && is_array($decoded)) {
                            $extJsonData = array_merge($extJsonData, $decoded);
                        } elseif ($decoded !== null) {
                            // Se è un valore scalare, lo mettiamo in un array con una chiave generica
                            $extJsonData['value'] = $decoded;
                        }
                    } elseif (is_array($value)) {
                        $extJsonData = array_merge($extJsonData, $value);
                    } else {
                        // Se è un valore scalare, lo mettiamo in un array con una chiave generica
                        $extJsonData['value'] = $value;
                    }
                }
            }
        }

        // Se ci sono dati ext_json, aggiorna il record
        if (!empty($extJsonData)) {
            $currentExtJson = DB::table("{$this->masterSchema}.records")
                ->where('id', $masterRecordId)
                ->value('ext_json');

            // Assicurati che currentData sia sempre un array
            $currentData = [];
            if ($currentExtJson !== null) {
                if (is_string($currentExtJson)) {
                    $decoded = json_decode($currentExtJson, true);
                    $currentData = is_array($decoded) ? $decoded : [];
                } elseif (is_array($currentExtJson)) {
                    $currentData = $currentExtJson;
                }
            }

            $mergedData = array_merge($currentData, $extJsonData);

            DB::table("{$this->masterSchema}.records")
                ->where('id', $masterRecordId)
                ->update([
                    'ext_json' => json_encode($mergedData),
                    'updated_at' => now(),
                ]);

            Log::debug('MirrorToMasterImporter: ext_json updated for record', [
                'master_record_id' => $masterRecordId,
                'ext_json_keys' => array_keys($mergedData),
            ]);
        }
    }

    /**
     * Importa i campi aggiuntivi dalla tabella added_kv per i record già importati nel Master.
     *
     * Legge i campi aggiuntivi dalla tabella added_kv dello schema Mirror
     * e li importa nel Master usando il mapping definito in added-fields-to-master.yaml.
     *
     * @return array<string, int>  Statistiche dell'importazione
     */
    public function importAddedFields(): array
    {
        Log::info('MirrorToMasterImporter: Starting added fields import', [
            'mirror_schema' => $this->mirrorSchema,
            'master_schema' => $this->masterSchema,
            'institution_id' => $this->institutionId,
        ]);

        // Reset statistiche per l'importazione dei campi aggiuntivi
        $this->stats = [
            'processed' => 0,
            'success' => 0,
            'errors' => 0,
            'warnings' => 0,
        ];

        try {
            // Carica il mapping per i campi aggiuntivi
            $addedFieldsResolver = new MappingResolver('added-fields-to-master.yaml');

            // Trova tutti i record Master già importati (usando stable_id che corrisponde a record_id nel Mirror)
            $masterRecords = DB::table("{$this->masterSchema}.records")
                ->where('primary_institution_id', $this->institutionId)
                ->get(['id', 'stable_id']);

            Log::info('MirrorToMasterImporter: Found master records for added fields import', [
                'total_records' => $masterRecords->count(),
            ]);

            foreach ($masterRecords as $masterRecord) {
                $stableId = $masterRecord->stable_id;
                $masterRecordId = $masterRecord->id;

                Log::debug('MirrorToMasterImporter: Processing added fields for record', [
                    'stable_id' => $stableId,
                    'master_record_id' => $masterRecordId,
                ]);

                $this->stats['processed']++;

                try {
                    // Carica i campi aggiuntivi dalla tabella added_kv (solo promoted = false)
                    $addedKvPairs = $this->loadAddedKvPairs($stableId);

                    if (empty($addedKvPairs)) {
                        Log::debug('MirrorToMasterImporter: No added fields found for record', [
                            'stable_id' => $stableId,
                        ]);
                        $this->stats['success']++;
                        continue;
                    }

                    // Applica il mapping usando il resolver per i campi aggiuntivi
                    $resolvedData = $addedFieldsResolver->resolve($addedKvPairs);

                    Log::debug('MirrorToMasterImporter: Resolved added fields data', [
                        'stable_id' => $stableId,
                        'tables' => array_keys($resolvedData),
                        'record_kv_count' => count($resolvedData['record_kv'] ?? []),
                    ]);

                    // Importa i dati risolti nel Master (solo record_kv, non creiamo nuovi record)
                    if (isset($resolvedData['record_kv']) && !empty($resolvedData['record_kv'])) {
                        $this->upsertRecordKv($masterRecordId, $resolvedData['record_kv']);
                    }

                    // Aggiorna promoted = true per tutti i record added_kv del record_id corrente
                    // dopo l'importazione riuscita
                    $this->markAddedKvAsPromoted($stableId);

                    $this->stats['success']++;
                } catch (\Exception $e) {
                    Log::error('MirrorToMasterImporter: Error importing added fields for record', [
                        'stable_id' => $stableId,
                        'master_record_id' => $masterRecordId,
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ]);

                    $this->stats['errors']++;
                }
            }

            Log::info('MirrorToMasterImporter: Added fields import completed', $this->stats);
        } catch (\Exception $e) {
            Log::error('MirrorToMasterImporter: Fatal error during added fields import', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'stats' => $this->stats,
            ]);
            throw $e;
        }

        return $this->stats;
    }

    /**
     * Aggiorna promoted = true per tutti i record added_kv di un record_id.
     *
     * Questo metodo viene chiamato dopo l'importazione riuscita dei campi aggiuntivi
     * per marcare i record come già importati nel Master.
     *
     * @param  string  $recordId  ID del record (stable_id nel Master)
     * @return void
     */
    private function markAddedKvAsPromoted(string $recordId): void
    {
        $addedKvModel = MirrorAddedKv::forSchema($this->mirrorSchema);
        $updated = $addedKvModel->newQuery()
            ->where('record_id', $recordId)
            ->where('promoted', false)
            ->update(['promoted' => true]);

        Log::debug('MirrorToMasterImporter: Marked added_kv records as promoted', [
            'record_id' => $recordId,
            'updated_count' => $updated,
        ]);
    }

    /**
     * Carica tutte le righe added_kv associate a un record_id.
     *
     * Carica solo i record con promoted = false (non ancora importati nel Master).
     *
     * @param  string  $recordId  ID del record (stable_id nel Master)
     * @return array<int, array<string, mixed>>  Array di KV pairs nel formato atteso da MappingResolver
     */
    private function loadAddedKvPairs(string $recordId): array
    {
        $addedKvModel = MirrorAddedKv::forSchema($this->mirrorSchema);
        $addedKvRecords = $addedKvModel->newQuery()
            ->where('record_id', $recordId)
            ->where('promoted', false)
            ->get();

        // Trasforma i dati added_kv nel formato atteso da MappingResolver
        // MappingResolver si aspetta: ['xpath' => ..., 'value_text' => ..., 'occurrence_idx' => ...]
        // Per added_kv: field_name corrisponde a source_field nel mapping
        return $addedKvRecords->map(function ($kv) {
            return [
                'xpath' => $kv->field_name, // field_name è il source_field nel mapping
                'value_text' => $kv->value_text,
                'occurrence_idx' => 0, // added_kv non ha occurrence_idx, usiamo 0
            ];
        })->toArray();
    }

    /**
     * Ottiene le statistiche dell'importazione.
     *
     * @return array<string, int>
     */
    public function getStats(): array
    {
        return $this->stats;
    }
}
