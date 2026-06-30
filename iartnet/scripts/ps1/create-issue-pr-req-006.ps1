#!/usr/bin/env pwsh
# Script per creare Issue e PR per REQ-006
# Usage: .\scripts\ps1\create-issue-pr-req-006.ps1 -GitHubToken "your_token"

param(
    [Parameter(Mandatory=$false)]
    [string]$GitHubToken,

    [string]$RepoOwner = "GPAManagementServices",
    [string]$RepoName = "iartnet",
    [string]$BranchName = "feature/req-006-laravel-filament-init"
)

# Se il token non Ã¨ fornito, mostra istruzioni
if (-not $GitHubToken) {
    Write-Output "âŒ GitHub Personal Access Token richiesto!"
    Write-Output ""
    Write-Output "Per creare Issue e PR automaticamente, esegui:"
    Write-Output "  .\scripts\ps1\create-issue-pr-req-006.ps1 -GitHubToken 'il_tuo_token'"
    Write-Output ""
    Write-Output "Per creare il token:"
    Write-Output "  1. Vai su: https://github.com/settings/tokens"
    Write-Output "  2. Clicca 'Generate new token (classic)'"
    Write-Output "  3. Seleziona permesso 'repo'"
    Write-Output "  4. Copia il token generato"
    Write-Output ""
    Write-Output "Oppure segui le istruzioni in: QUICK-CREATE-ISSUE-PR.md"
    Write-Output ""
    exit 1
}

$ErrorActionPreference = "Stop"

$baseUrl = "https://api.github.com"
$headers = @{
    "Authorization" = "token $GitHubToken"
    "Accept" = "application/vnd.github.v3+json"
    "User-Agent" = "PowerShell"
}

Write-Output "Creating GitHub Issue for REQ-006..."

# Issue body
$issueBody = @"
## Requisito
**REQ-006**: Laravel 12 + Filament 3 Backend Initialization

## Descrizione
Inizializzare l'applicazione backend utilizzando Laravel 12 con pannello amministrativo Filament 3 nella directory `apps/api/`. Questo stabilisce le basi per la gestione del Master Database e del backoffice amministrativo.

## Criteri di Accettazione

- [x] Applicazione Laravel 12 inizializzata in `apps/api/`
- [x] `composer.json` configurato con dipendenze Laravel 12
- [ ] Filament 3 installato e configurato
- [ ] Pannello Filament base creato
- [ ] Configurazione database per PostgreSQL
- [ ] Configurazione Redis per cache/queues
- [ ] File di ambiente (`.env.example`) configurati
- [ ] Test base in esecuzione

## Implementazione

### File Creati
- `apps/api/composer.json` - Dipendenze Laravel 12
- `apps/api/.env.example` - Template ambiente
- `apps/api/config/` - File di configurazione
- `apps/api/database/migrations/` - Migrazioni database
- `apps/api/routes/` - Route applicazione
- `apps/api/app/` - Logica applicazione

### Prossimi Passi
1. Installare Filament 3: `composer require filament/filament:"^3.0"`
2. Creare pannello Filament: `php artisan filament:install --panels`
3. Configurare connessione PostgreSQL
4. Configurare Redis per cache/queues
5. Creare risorse e modelli iniziali

## Riferimenti
- [Documentazione Requisito](docs/requirements/REQ-006-laravel-filament-init.md)
- [Traceability Matrix](docs/traceability/traceability-matrix.md#req-006)
- [ADR-0001: Monorepo Structure](docs/adr/0001-repo-structure.md)
"@

$issueData = @{
    title = "[REQ-006] Inizializzazione Laravel 12 + Filament 3 Backend"
    body = $issueBody
    labels = @("requirement", "backend", "laravel", "filament")
} | ConvertTo-Json

try {
    $issueResponse = Invoke-RestMethod -Uri "$baseUrl/repos/$RepoOwner/$RepoName/issues" `
        -Method Post `
        -Headers $headers `
        -Body $issueData `
        -ContentType "application/json"

    $issueNumber = $issueResponse.number
    $issueUrl = $issueResponse.html_url

    Write-Output "âœ… Issue created: #$issueNumber"
    Write-Output "   URL: $issueUrl"
    Write-Output ""

    Write-Output "Creating Pull Request..."

    # PR body
    $prBody = @"
## Requisito
Closes #$issueNumber

**REQ-006**: Laravel 12 + Filament 3 Backend Initialization

## Descrizione
Inizializza l'applicazione backend Laravel 12 con struttura base per Filament 3 nella directory `apps/api/`.

## Modifiche

### File Aggiunti
- `apps/api/` - Applicazione Laravel 12 completa
  - 54 file creati (2350+ righe)
  - Struttura standard Laravel
  - Migrazioni database base
  - Configurazioni iniziali

### Documentazione
- `docs/requirements/REQ-006-laravel-filament-init.md` - Documentazione requisito
- `docs/traceability/traceability-matrix.md` - Aggiornato con REQ-006
- `.github/ISSUE_TEMPLATE/req-006-laravel-filament-init.md` - Template issue
- `docs/development/create-issue-req-006.md` - Istruzioni

## Criteri di Accettazione

- [x] Laravel 12 inizializzato in `apps/api/`
- [x] `composer.json` configurato
- [x] Struttura directory standard Laravel
- [x] Documentazione requisito creata
- [x] Traceability matrix aggiornata

## Prossimi Passi
- [ ] Installare Filament 3
- [ ] Configurare PostgreSQL
- [ ] Configurare Redis
- [ ] Creare pannello Filament base

## Riferimenti
- Issue: #$issueNumber
- Requisito: [REQ-006](docs/requirements/REQ-006-laravel-filament-init.md)
- Traceability: [Matrix](docs/traceability/traceability-matrix.md#req-006)
"@

    $prData = @{
        title = "feat: Initialize Laravel 12 + Filament 3 backend (REQ-006)"
        body = $prBody
        head = $BranchName
        base = "master"
    } | ConvertTo-Json

    $prResponse = Invoke-RestMethod -Uri "$baseUrl/repos/$RepoOwner/$RepoName/pulls" `
        -Method Post `
        -Headers $headers `
        -Body $prData `
        -ContentType "application/json"

    $prNumber = $prResponse.number
    $prUrl = $prResponse.html_url

    Write-Output "âœ… Pull Request created: #$prNumber"
    Write-Output "   URL: $prUrl"
    Write-Output ""
    Write-Output "ðŸŽ‰ Issue #$issueNumber and PR #$prNumber created successfully!"
    Write-Output ""
    Write-Output "Issue: $issueUrl"
    Write-Output "PR: $prUrl"

} catch {
    Write-Output "âŒ Error: $_"
    Write-Output ""
    Write-Output "Make sure:"
    Write-Output "  1. GitHub token has 'repo' permissions"
    Write-Output "  2. Branch '$BranchName' exists on remote"
    Write-Output "  3. You have write access to the repository"
    exit 1
}
