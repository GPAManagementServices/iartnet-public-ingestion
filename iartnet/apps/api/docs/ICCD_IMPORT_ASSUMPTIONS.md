# ICCD Import - Assunzioni e Note Implementative

## Data: 2026-01-17

## Assunzioni sulla Struttura delle Tabelle Mirror

Le seguenti assunzioni sono state fatte sulla struttura delle tabelle nello schema mirror per l'import ICCD:

### Tabella `record`
**Struttura assunta:**
- `id` (UUID) - Primary key
- `batch_id` (UUID) - Foreign key verso `import_runs.batch_id`
- `scheda_id` (TEXT) - ID della scheda ICCD
- `xml_content` (TEXT/XML) - Contenuto XML completo della scheda
- `created_at` (TIMESTAMP)

**Nota:** Se la struttura è diversa, modificare il metodo `importRecord()` in `IccdImportService`.

### Tabella `kv`
**Struttura assunta:**
- `id` (UUID) - Primary key
- `record_id` (UUID) - Foreign key verso `record.id`
- `key` (TEXT) - Chiave del key-value pair (path XML, es. "OGTD/OGT/OGT.OGTN")
- `value` (TEXT) - Valore del key-value pair
- `created_at` (TIMESTAMP)

**Nota:** Se la struttura è diversa, modificare il metodo `importKeyValuePairs()` in `IccdImportService`.

### Tabella `asset`
**Struttura assunta:**
- `id` (UUID) - Primary key
- `batch_id` (UUID) - Foreign key verso `import_runs.batch_id`
- `record_id` (TEXT/UUID) - Riferimento alla scheda (scheda_id da IMMFTAN)
- `file_path` (TEXT) - Path completo del file media
- `file_name` (TEXT) - Nome del file
- `file_type` (TEXT) - Tipo file: 'image', 'audio', 'video', 'document', 'other'
- `created_at` (TIMESTAMP)

**Nota:** Se la struttura è diversa, modificare il metodo `importAssets()` in `IccdImportService`.

### Tabella `validation_issue`
**Struttura assunta:**
- `id` (UUID) - Primary key
- `batch_id` (UUID) - Foreign key verso `import_runs.batch_id`
- `file` (TEXT) - Nome del file validato
- `scheda_id` (TEXT, nullable) - ID della scheda se disponibile
- `severity` (TEXT) - 'error', 'warning', 'info'
- `message` (TEXT) - Messaggio di errore/warning
- `line_num` (INT, nullable) - Numero di riga
- `column_num` (INT, nullable) - Numero di colonna
- `created_at` (TIMESTAMP)

**Nota:** Se la struttura è diversa, modificare il metodo `importValidationIssues()` in `IccdImportService`.

## Path e Configurazione

### Saxon HE JAR
- **Path:** `tools/saxson/saxon-he-12.9.jar`
- **Nota:** La directory ha un typo ("saxson" invece di "saxon") ma il path è corretto per il filesystem attuale.

### Directory Storage
- **XSD:** `storage/iccd/xsd/`
- **TMP:** `storage/iccd/tmp/<runId>/`
- **Uploads:** `storage/app/iccd/uploads/`
- **Runs:** `storage/app/iccd/runs/<runId>/`
- **Data extraction:** `data/<runId>/`

## Mapping XSD

I seguenti mapping sono configurati in `SaxonValidator`:

- **OA files** (pattern: `*OA.xml`) → `ICCD_OA_3.00_062018.xsd`
- **S files** (pattern: `*S.xml`) → `ICCD_S_3.00.xsd`
- **INFORMA.xml** → `informa.xsd`
- **IMMFTAN.xml** → `immftan.xsd`

## Encoding

Il parser XML gestisce automaticamente:
- **ISO-8859-1** (codifica storica ICCD) - fallback se non specificato
- **UTF-8** - se dichiarato nel XML declaration

## Limitazioni e Protezioni

### ZIP Package
- **Max files:** 5000 (configurabile in `ZipPackageInspector::MAX_FILES`)
- **Max size:** 2GB (configurabile in `ZipPackageInspector::MAX_TOTAL_SIZE`)
- **Protezione zip slip:** Implementata con validazione path e realpath check

### Estensioni File Consentite
- XML: `.xml`
- Immagini: `.jpg`, `.jpeg`, `.png`, `.tif`, `.tiff`
- Documenti: `.pdf`
- Audio: `.mp3`
- Video: `.mp4`

## Note di Implementazione

1. **Import sincrono:** L'import è attualmente sincrono. Per pacchetti molto grandi (>1000 schede), considerare l'implementazione di un Job asincrono.

2. **Parsing XML:** Usa `DOMDocument` e `DOMXPath` per parsing (non libxml per validazione XSD, come richiesto).

3. **Gestione errori:** Gli errori durante l'import di singole schede non bloccano l'intero processo; vengono loggati e contati.

4. **Validazione:** La validazione XSD 1.1 è opzionale - l'utente può procedere anche con errori di validazione.

## Verifica Post-Implementazione

Prima di utilizzare in produzione, verificare:

1. ✅ Struttura tabelle corrisponde alle assunzioni
2. ✅ Saxon HE JAR è accessibile e funzionante
3. ✅ Permessi filesystem per directory di estrazione/storage
4. ✅ Test con pacchetto ICCD reale
5. ✅ Verifica encoding corretto per file ISO-8859-1
