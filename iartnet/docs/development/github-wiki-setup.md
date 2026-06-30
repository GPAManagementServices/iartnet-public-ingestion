# Setup GitHub Wiki per IARTNET

## Panoramica

GitHub Wiki è un repository Git separato che può essere clonato e modificato
indipendentemente dal repository principale. È ideale per documentazione che
deve essere facilmente accessibile e modificabile.

## Abilitazione Wiki

1. Vai a **Settings** del repository
2. Nella sezione **Features**, abilita **Wikis**
3. (Opzionale) Disabilita **Restrict editing to collaborators only** se vuoi
   permettere modifiche pubbliche

## Struttura Wiki

La Wiki di GitHub non supporta sottocartelle come `docs/`. Invece, usa nomi
file con spazi o trattini per organizzare le pagine.

**Struttura consigliata**:

```text
Home.md                    # Pagina principale
Architecture.md            # Architettura completa
Getting-Started.md         # Guida per nuovi developer
Cursor-IDE-Setup.md        # Setup per Cursor
Local-Development.md        # Runbook sviluppo locale
Security.md                # Documentazione sicurezza
CI-CD.md                   # Workflow GitHub Actions
Troubleshooting.md         # Risoluzione problemi comuni
```

## Integrazione Documento Architettura

### Metodo 1: Copia Diretta (Consigliato)

Il file `docs/architecture/README.md` può essere copiato direttamente nella Wiki
con alcune modifiche minori:

1. **Rinomina**: `README.md` → `Architecture.md`
2. **Aggiorna link interni**: I link relativi devono essere adattati
3. **Aggiungi link Home**: Aggiungi link alla Home page

### Metodo 2: Link dal Repository

In alternativa, puoi linkare direttamente al file nel repository:

```markdown
Vedi [Architettura Completa](../blob/main/docs/architecture/README.md)
```

## Passi per Integrazione

### Step 1: Clona Wiki Repository

```bash
# La Wiki ha un URL separato
git clone https://github.com/GPAManagementServices/iartnet.wiki.git
cd iartnet.wiki
```

### Step 2: Crea Home Page

Crea `Home.md`:

```markdown
# IARTNET Wiki

Benvenuto nella documentazione del progetto IARTNET.

## Indice

* [Architettura Completa](Architecture)
* [Guida per Nuovi Developer](Getting-Started)
* [Setup Cursor IDE](Cursor-IDE-Setup)
* [Sviluppo Locale](Local-Development)
* [Sicurezza](Security)
* [CI/CD](CI-CD)
* [Troubleshooting](Troubleshooting)

## Link Utili

* [Repository Principale](https://github.com/GPAManagementServices/iartnet)
* [Issues](https://github.com/GPAManagementServices/iartnet/issues)
* [Pull Requests](https://github.com/GPAManagementServices/iartnet/pulls)
```

### Step 3: Copia e Adatta Architettura

```bash
# Copia il file
cp ../iartnet/docs/architecture/README.md Architecture.md

# Modifica i link interni (vedi sezione seguente)
```

### Step 4: Adatta Link Interni

I link nella Wiki funzionano diversamente:

**Nel repository**:
```markdown
[docs/runbooks/local-dev.md](runbooks/local-dev.md)
```

**Nella Wiki**:
```markdown
[Local Development](Local-Development)
```

**Script di conversione** (esempio PowerShell):

```powershell
# Sostituisci link relativi con link Wiki
$content = Get-Content Architecture.md -Raw
$content = $content -replace '\[([^\]]+)\]\(runbooks/local-dev\.md\)', '[Local Development](Local-Development)'
$content = $content -replace '\[([^\]]+)\]\(security/README\.md\)', '[Security](Security)'
# ... altre sostituzioni
$content | Out-File Architecture.md -Encoding UTF8
```

### Step 5: Commit e Push

```bash
git add .
git commit -m "docs: aggiunta documentazione architettura"
git push origin master
```

## Best Practices Wiki

### 1. Naming Convention

* Usa **PascalCase** o **kebab-case** per nomi file
* Evita spazi nei nomi file (usa trattini)
* Esempi: `Getting-Started.md`, `CI-CD.md`

### 2. Link Interni

**Formato corretto**:
```markdown
[Testo Link](Nome-Pagina)
```

**Non usare**:
```markdown
[Testo Link](Nome-Pagina.md)  # .md non necessario
[Testo Link](./Nome-Pagina)   # ./ non funziona
```

### 3. Sidebar

Crea `_Sidebar.md` per navigazione:

```markdown
* [Home](Home)
* [Architettura](Architecture)
* [Getting Started](Getting-Started)
* [Cursor IDE](Cursor-IDE-Setup)
* [Sviluppo Locale](Local-Development)
* [Sicurezza](Security)
* [CI/CD](CI-CD)
* [Troubleshooting](Troubleshooting)
```

### 4. Footer

Crea `_Footer.md` per informazioni comuni:

```markdown
---
**IARTNET** - Integrated Art Network Platform

[Repository](https://github.com/GPAManagementServices/iartnet) |
[Issues](https://github.com/GPAManagementServices/iartnet/issues) |
[Documentazione](https://github.com/GPAManagementServices/iartnet/tree/main/docs)
```

## Automazione

### Script PowerShell per Sincronizzazione

Crea `scripts/ps1/sync-wiki.ps1`:

```powershell
#!/usr/bin/env pwsh
# Sincronizza documentazione con GitHub Wiki

$ErrorActionPreference = "Stop"

$repoRoot = Split-Path -Parent $PSScriptPath
$wikiPath = "$repoRoot/../iartnet.wiki"

if (-not (Test-Path $wikiPath)) {
    Write-Output "Cloning wiki repository..."
    git clone https://github.com/GPAManagementServices/iartnet.wiki.git $wikiPath
}

# Copia e adatta file
Write-Output "Copying architecture documentation..."
Copy-Item "$repoRoot/docs/architecture/README.md" "$wikiPath/Architecture.md"

# Adatta link (esempio base)
$content = Get-Content "$wikiPath/Architecture.md" -Raw
# Aggiungi qui le sostituzioni necessarie
$content | Out-File "$wikiPath/Architecture.md" -Encoding UTF8

Write-Output "Wiki synchronized. Commit and push manually:"
Write-Output "  cd $wikiPath"
Write-Output "  git add ."
Write-Output "  git commit -m 'docs: update architecture'"
Write-Output "  git push"
```

### GitHub Action per Auto-Sync

Crea `.github/workflows/sync-wiki.yml`:

```yaml
---
name: Sync Wiki

on:
  push:
    paths:
      - 'docs/architecture/README.md'
    branches: [main, develop]
  workflow_dispatch:

jobs:
  sync:
    runs-on: ubuntu-latest
    steps:
      - name: Checkout main repo
        uses: actions/checkout@v4.1.1

      - name: Checkout wiki
        uses: actions/checkout@v4.1.1
        with:
          repository: GPAManagementServices/iartnet.wiki
          path: wiki

      - name: Copy architecture docs
        run: |
          cp docs/architecture/README.md wiki/Architecture.md

      - name: Commit and push
        run: |
          cd wiki
          git config user.name "github-actions[bot]"
          git config user.email "github-actions[bot]@users.noreply.github.com"
          git add .
          git diff --staged --quiet || git commit -m "docs: sync architecture from main repo"
          git push
        env:
          GITHUB_TOKEN: ${{ secrets.GITHUB_TOKEN }}
```

## Accesso Wiki

Dopo l'integrazione, la Wiki sarà disponibile su:

```text
https://github.com/GPAManagementServices/iartnet/wiki
```

## Manutenzione

### Aggiornamento Manuale

1. Modifica file nella Wiki direttamente su GitHub
2. Oppure clona, modifica localmente, commit e push

### Sincronizzazione da Repository

1. Esegui script di sync (se configurato)
2. Oppure copia manualmente file aggiornati

## Riferimenti

* [GitHub Wiki Documentation](https://docs.github.com/en/communities/documenting-your-project-with-wikis)
* [Wiki Markdown Guide](https://docs.github.com/en/get-started/writing-on-github/getting-started-with-writing-and-formatting-on-github)
