# 🔧 Come Risolvere Errori Super Linter

## 📋 Procedura Step-by-Step

### Step 1: Identificare gli Errori

1. **Vai sulla tua Pull Request su GitHub**
2. **Scorri fino alla sezione "Checks"** (in fondo alla PR)
3. **Clicca sul check "Super-Linter"** o "Lint Code Base" (se fallito, sarà rosso ❌)
4. **Espandi i dettagli** per vedere gli errori specifici

Oppure:
- Vai su **Actions** → trova il workflow "Super-Linter" → apri il run fallito
- Clicca su **"Lint Code Base"** per vedere i dettagli

### Step 2: Tipi di Errori Comuni

#### 🔴 PHP (PSR12 Standard)
**Errore tipico**: `Expected 1 newline at end of file`

**Soluzione**:
```bash
# Assicurati che tutti i file PHP finiscano con una newline
# La maggior parte degli editor lo fa automaticamente
```

**Errore tipico**: `Line indented incorrectly`

**Soluzione**:
- Usa 4 spazi (non tab) per l'indentazione
- Verifica che l'indentazione sia consistente

**Errore tipico**: `Missing doc comment`

**Soluzione**:
- Aggiungi PHPDoc ai metodi pubblici
- Esempio:
```php
/**
 * Get the user's name.
 *
 * @return string
 */
public function getName(): string
{
    return $this->name;
}
```

#### 🔴 Markdown
**Errore tipico**: `MD013/line-length` - Line too long

**Soluzione**:
```bash
# Auto-fix disponibile
markdownlint -f file.md
```

**Errore tipico**: `MD041/first-line-heading` - First line should be a heading

**Soluzione**:
- Assicurati che il file inizi con `# Titolo`

#### 🔴 YAML
**Errore tipico**: `syntax error: mapping values are not allowed here`

**Soluzione**:
- Verifica indentazione (usa spazi, non tab)
- Verifica che i due punti `:` abbiano uno spazio dopo
- Esempio corretto:
```yaml
key: value
list:
  - item1
  - item2
```

#### 🔴 PowerShell
**Errore tipico**: `PSUseDeclaredVarsMoreThanAssignments`

**Soluzione**:
- Inizializza le variabili prima di usarle
- Usa `$null` per inizializzare se necessario

**Errore tipico**: `PSAvoidUsingWriteHost`

**Soluzione**:
- Usa `Write-Output` invece di `Write-Host`

#### 🔴 Bash/Shell
**Errore tipico**: `Indentation error`

**Soluzione**:
```bash
# Auto-fix disponibile
shfmt -w script.sh
```

### Step 3: Correggere gli Errori

#### Metodo A: Correzione Manuale

1. **Apri il file con l'errore** nel tuo editor
2. **Leggi il messaggio di errore** per capire cosa correggere
3. **Applica la correzione**
4. **Salva il file**

#### Metodo B: Auto-Fix (quando disponibile)

**Markdown**:
```powershell
# Correggi tutti i file Markdown
markdownlint -f *.md docs/**/*.md
```

**Bash/Shell**:
```bash
# Correggi tutti gli script bash
shfmt -w init.sh
shfmt -w scripts/bash/*.sh
```

**PHP** (con PHP CS Fixer, se installato):
```bash
cd apps/api
vendor/bin/php-cs-fixer fix --rules=@PSR12 .
```

### Step 4: Verificare le Correzioni

**Opzione 1: Linting Locale** (se i tool sono installati):
```powershell
.\scripts\ps1\lint-local.ps1
```

**Opzione 2: Commit e Push** (verifica su GitHub):
```powershell
git add .
git commit -m "fix: resolve Super Linter errors"
git push
```

### Step 5: Verificare su GitHub

1. **Vai sulla PR**
2. **Attendi che Super Linter finisca** (pochi minuti)
3. **Verifica che il check sia verde** ✅
4. Se ci sono ancora errori, **ripeti dal Step 1**

## 🚨 Errori Comuni e Soluzioni Rapide

### "Expected 1 newline at end of file"
**Soluzione**: Aggiungi una riga vuota alla fine del file

### "Line too long" (Markdown)
**Soluzione**: 
```powershell
markdownlint -f file.md
```

### "Indentation error" (Bash)
**Soluzione**:
```bash
shfmt -w script.sh
```

### "PSUseDeclaredVarsMoreThanAssignments" (PowerShell)
**Soluzione**: Inizializza la variabile:
```powershell
$variable = $null  # o valore iniziale
```

### "Missing doc comment" (PHP)
**Soluzione**: Aggiungi PHPDoc:
```php
/**
 * Description here.
 *
 * @param type $param
 * @return type
 */
```

## 📝 Workflow Consigliato

1. ✅ **Vedi errori su GitHub** → Copia i messaggi di errore
2. ✅ **Correggi localmente** → Usa auto-fix quando possibile
3. ✅ **Verifica localmente** → `.\scripts\ps1\lint-local.ps1` (se tool installati)
4. ✅ **Commit e push** → `git commit -m "fix: resolve linter errors"`
5. ✅ **Verifica su GitHub** → Attendi Super Linter
6. ✅ **Ripeti se necessario** → Fino a quando tutti i check sono verdi

## 🔗 Link Utili

- [Super Linter Documentation](https://github.com/github/super-linter)
- [PSR-12 Coding Standard](https://www.php-fig.org/psr/psr-12/)
- [Markdownlint Rules](https://github.com/DavidAnson/markdownlint)
- [PowerShell Best Practices](https://docs.microsoft.com/en-us/powershell/scripting/developer/cmdlet/strongly-encouraged-development-guidelines)

## ⚠️ Nota Importante

**NON usare `--no-verify`** per saltare i check! Gli errori verranno comunque rilevati su GitHub e bloccheranno il merge. È meglio correggere gli errori prima.
