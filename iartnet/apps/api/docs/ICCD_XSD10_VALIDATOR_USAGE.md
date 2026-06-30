# ICCD XSD 1.0 Validator Service - Guida all'utilizzo

## Panoramica

Il service `IccdXsd10ValidatorService` fornisce validazione XSD 1.0 strutturale per file XML ICCD utilizzando PHP nativo (`DOMDocument` e `libxml`).

**Caratteristiche:**
- ✅ Validazione strutturale (struttura XML, namespaces, tipi, cardinalità)
- ✅ Open-source (PHP nativo, nessuna dipendenza esterna)
- ✅ Compatibile con XSD 1.0
- ❌ NON supporta XSD 1.1 (xs:assert, ecc.)

## Struttura del Service

```php
namespace App\Services\Iccd;

class IccdXsd10ValidatorService
{
    // Metodi pubblici:
    public function validate(string $xmlPath, string $xsdPath): array<ValidationIssue>
    public function getXsdPathForXml(string $xmlPath): ?string
    public function validateMultiple(array $xmlFiles): array<string, array<ValidationIssue>>
}
```

## Esempio 1: Validazione singolo file

```php
use App\Services\Iccd\IccdXsd10ValidatorService;

$validator = app(IccdXsd10ValidatorService::class);

// Validazione esplicita con path XSD
$xmlPath = '/path/to/SAI652OA.xml';
$xsdPath = base_path('storage/iccd/xsd/ICCD_OA_3.00_062018.xsd');

$issues = $validator->validate($xmlPath, $xsdPath);

if (empty($issues)) {
    echo "File valido!\n";
} else {
    foreach ($issues as $issue) {
        echo sprintf(
            "[%s] %s (line %d, col %d): %s\n",
            $issue->severity,
            $issue->file,
            $issue->line ?? 0,
            $issue->column ?? 0,
            $issue->message
        );
    }
}
```

## Esempio 2: Validazione con auto-mapping XSD

```php
use App\Services\Iccd\IccdXsd10ValidatorService;

$validator = app(IccdXsd10ValidatorService::class);

$xmlPath = '/path/to/SAI652OA.xml';

// Trova automaticamente l'XSD corretto
$xsdPath = $validator->getXsdPathForXml($xmlPath);

if ($xsdPath === null) {
    echo "Nessun XSD trovato per questo file\n";
    return;
}

$issues = $validator->validate($xmlPath, $xsdPath);

// Processa i risultati...
```

## Esempio 3: Validazione multipla

```php
use App\Services\Iccd\IccdXsd10ValidatorService;

$validator = app(IccdXsd10ValidatorService::class);

$xmlFiles = [
    '/path/to/SAI652OA.xml',
    '/path/to/SAI652S.xml',
    '/path/to/INFORMA.xml',
    '/path/to/IMMFTAN.xml',
];

// Valida tutti i file (auto-mapping XSD incluso)
$results = $validator->validateMultiple($xmlFiles);

foreach ($results as $filePath => $issues) {
    $fileName = basename($filePath);
    
    if (empty($issues)) {
        echo "✓ {$fileName}: valido\n";
    } else {
        $errorCount = count(array_filter($issues, fn($i) => $i->severity === 'error'));
        $warningCount = count(array_filter($issues, fn($i) => $i->severity === 'warning'));
        
        echo "✗ {$fileName}: {$errorCount} errori, {$warningCount} warning\n";
        
        foreach ($issues as $issue) {
            echo "  - [{$issue->severity}] {$issue->message}\n";
            if ($issue->line) {
                echo "    (line {$issue->line}, col {$issue->column})\n";
            }
        }
    }
}
```

## Esempio 4: Integrazione in Controller/Command

```php
use App\Services\Iccd\IccdXsd10ValidatorService;
use Illuminate\Http\Request;

class IccdImportController extends Controller
{
    public function validate(Request $request, IccdXsd10ValidatorService $validator)
    {
        $xmlPath = $request->input('xml_path');
        $xsdPath = $request->input('xsd_path');
        
        try {
            $issues = $validator->validate($xmlPath, $xsdPath);
            
            $hasErrors = !empty(array_filter($issues, fn($i) => $i->severity === 'error'));
            
            return response()->json([
                'valid' => empty($issues),
                'has_errors' => $hasErrors,
                'errors' => array_map(fn($i) => $i->toArray(), $issues),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'valid' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
```

## Struttura di Output

### ValidationIssue

```php
readonly class ValidationIssue
{
    public string $file;           // Nome file (basename)
    public string $severity;        // 'error' | 'warning' | 'info'
    public string $message;         // Messaggio di errore pulito
    public ?string $schedaId;      // ID scheda (se disponibile)
    public ?int $line;             // Numero riga (se disponibile)
    public ?int $column;           // Numero colonna (se disponibile)
    
    public function toArray(): array;
}
```

### Esempio JSON Output

```json
{
  "valid": false,
  "errors": [
    {
      "file": "SAI652OA.xml",
      "severity": "error",
      "message": "Element 'CDN' is not allowed in this context",
      "scheda_id": null,
      "line": 42,
      "column": 15
    },
    {
      "file": "SAI652OA.xml",
      "severity": "warning",
      "message": "Attribute 'versione' is not declared",
      "scheda_id": null,
      "line": 10,
      "column": 5
    }
  ]
}
```

## Mapping XSD Automatico

Il service supporta mapping automatico basato su:

1. **Nome file esatto:**
   - `INFORMA.XML` → `informa.xsd`
   - `IMMFTAN.XML` → `immftan.xsd`

2. **Pattern ICCD:**
   - `S{CODICE}{TIPO}.xml` → `ICCD_{TIPO}_3.00.xsd`
   - `A{CODICE}{TIPO}.xml` → `ICCD_{TIPO}_3.00.xsd`
   
   Esempi:
   - `SAI652OA.xml` → `ICCD_OA_3.00_062018.xsd`
   - `SAI652S.xml` → `ICCD_S_3.00.xsd`

## Limitazioni

⚠️ **XSD 1.1 non supportato:**
- `xs:assert` viene ignorato
- `xs:alternative` viene ignorato
- Altri costrutti XSD 1.1 non validati

⚠️ **Validazione strutturale:**
- Verifica struttura, namespaces, tipi, cardinalità
- NON verifica regole logiche avanzate (ex xs:assert)

## Gestione Errori

```php
try {
    $issues = $validator->validate($xmlPath, $xsdPath);
} catch (RuntimeException $e) {
    // File non trovato o altri errori di sistema
    echo "Errore: " . $e->getMessage() . "\n";
}
```

## Best Practices

1. **Sempre controllare esistenza file prima:**
   ```php
   if (!file_exists($xmlPath)) {
       // Gestisci errore
   }
   ```

2. **Usare auto-mapping quando possibile:**
   ```php
   $xsdPath = $validator->getXsdPathForXml($xmlPath);
   if ($xsdPath === null) {
       // Nessun mapping disponibile
   }
   ```

3. **Filtrare per severity:**
   ```php
   $errors = array_filter($issues, fn($i) => $i->severity === 'error');
   $warnings = array_filter($issues, fn($i) => $i->severity === 'warning');
   ```

4. **Logging:**
   ```php
   Log::info("Validation completed", [
       'file' => $xmlPath,
       'issues_count' => count($issues),
   ]);
   ```
