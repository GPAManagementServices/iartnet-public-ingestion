# Mirror to Master Import System

Sistema di importazione dati dallo Schema Mirror allo Schema Master (Dublin Core + EDM).

## Architettura

Il sistema è composto da:

1. **MappingResolver** (`MappingResolver.php`)
   - Interpreta file di mapping (YAML o JSON)
   - Risolve valori dal Mirror KV
   - Supporta trasformazioni e validazioni

2. **MirrorToMasterImporter** (`MirrorToMasterImporter.php`)
   - Service principale per l'importazione
   - Carica mapping, legge record e KV dal Mirror
   - Applica mapping e scrive nel Master
   - Gestisce transazioni per singolo record

3. **ImportMirrorToMasterJob** (`../Jobs/ImportMirrorToMasterJob.php`)
   - Job per esecuzione in background
   - Gestisce logging ed errori
   - Supporta retry automatici

## File di Mapping

I file di mapping sono posizionati in `storage/mapping/` e definiscono come mappare i campi ICCD (dal Mirror) alle tabelle Master.

### Formato

Supporta due formati:
- **YAML** (richiede `symfony/yaml`: `composer require symfony/yaml`)
- **JSON** (nativo, nessuna dipendenza aggiuntiva)

### Struttura

```yaml
mappings:
  - source_field: "OGTD/OGT/OGT.OGTN"  # Campo ICCD sorgente (xpath)
    target_table: "records"              # Tabella Master
    target_field: "stable_id"            # Campo nella tabella
    required: true                        # Campo obbligatorio
    multiple: false                      # Supporta valori multipli
    transform: "uppercase"                # Trasformazione opzionale
    description: "ID stabile del record"

config:
  master_schema: "iartnet_master"
  default_record:
    edm_type: "TEXT"
    publish_state: "draft"
    primary_lang: "it"
  on_missing_field: "warn"               # warn | error | skip
  transaction_per_record: true
```

### Trasformazioni Supportate

- `uppercase`: Converte in maiuscolo
- `lowercase`: Converte in minuscolo
- `trim`: Rimuove spazi iniziali/finali
- `date_parse`: Parsing date (TODO: implementare logica ICCD)
- `json_merge`: Merge valori multipli in JSON (TODO: implementare)

## Utilizzo

### Importazione Diretta

```php
use App\Services\Import\MirrorToMasterImporter;

$importer = new MirrorToMasterImporter(
    'mirror_schema_name',      // Schema Mirror
    'iccd-to-master.yaml',      // File di mapping
    'institution-uuid'          // ID istituzione
);

$stats = $importer->importAll();
// ['processed' => 10, 'success' => 9, 'errors' => 1, 'warnings' => 0]
```

### Importazione in Background

```php
use App\Jobs\ImportMirrorToMasterJob;

ImportMirrorToMasterJob::dispatch(
    'mirror_schema_name',
    'iccd-to-master.yaml',
    'institution-uuid'
);
```

## Flusso di Importazione

Per ogni record nello schema Mirror:

1. **Caricamento Record**: Legge `record` dal Mirror usando `record_id`
2. **Caricamento KV**: Carica tutte le righe `kv` associate al `record_id`
3. **Applicazione Mapping**: `MappingResolver` risolve i valori secondo il mapping
4. **Scrittura Master**:
   - Crea/aggiorna record principale in `records`
   - Gestisce entità correlate (agents, concepts, places, timespans)
   - Aggiorna `ext_json` se necessario

## Gestione Errori

- **Campo obbligatorio mancante**: Warning log (configurabile via `on_missing_field`)
- **record_id mancante**: Errore bloccante (configurabile via `on_missing_record_id`)
- **Errore durante scrittura**: Rollback transazione (se `transaction_per_record: true`)

## TODO

### MappingResolver
- [ ] Implementare logica parsing date ICCD in `transformDate()`
- [ ] Implementare logica merge JSON in `transformJsonMerge()`
- [ ] Gestire `on_missing_field` secondo configurazione

### MirrorToMasterImporter
- [ ] Implementare `upsertAgents()` con creazione/lookup agent
- [ ] Implementare `upsertConcepts()` con creazione/lookup concept
- [ ] Implementare `upsertPlaces()` con creazione/lookup place
- [ ] Implementare `upsertTimespans()` con creazione/lookup timespan
- [ ] Implementare `updateExtJson()` con logica merge JSON

### File di Mapping
- [ ] Definire mapping completo ICCD → DC/EDM
- [ ] Gestire campi multipli correttamente
- [ ] Definire struttura `ext_json` per ogni tipo di record

## Note

- Il sistema non modifica gli schemi di database
- Le transazioni sono per singolo record (configurabile)
- Il logging è completo per debugging e audit
- Il sistema è estendibile a più profili ICCD creando nuovi file di mapping
