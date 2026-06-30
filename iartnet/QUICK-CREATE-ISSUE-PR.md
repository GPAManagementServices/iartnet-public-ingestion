# 🚀 Creazione Rapida Issue e PR per REQ-006

## ⚡ Link Diretti

### 1. Creare Issue
👉 **Clicca qui**: https://github.com/GPAManagementServices/iartnet/issues/new

### 2. Creare Pull Request  
👉 **Clicca qui**: https://github.com/GPAManagementServices/iartnet/pull/new/feature/req-006-laravel-filament-init

---

## 📝 Issue - Contenuto da Copiare

**Titolo:**
```
[REQ-006] Inizializzazione Laravel 12 + Filament 3 Backend
```

**Corpo (copia tutto):**
```markdown
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
```

**Labels da aggiungere:**
- `requirement`
- `backend`
- `laravel`
- `filament`

**⚠️ IMPORTANTE:** Dopo aver creato l'issue, **copia il numero** (es. #15) per usarlo nella PR!

---

## 🔀 Pull Request - Contenuto da Copiare

**Titolo:**
```
feat: Initialize Laravel 12 + Filament 3 backend (REQ-006)
```

**Corpo (sostituisci `#XXX` con il numero dell'issue creata):**
```markdown
## Requisito
Closes #XXX

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
- Issue: #XXX
- Requisito: [REQ-006](docs/requirements/REQ-006-laravel-filament-init.md)
- Traceability: [Matrix](docs/traceability/traceability-matrix.md#req-006)
```

**Base branch:** `master`  
**Compare branch:** `feature/req-006-laravel-filament-init`

---

## ✅ Checklist

- [ ] Issue creata con numero #XXX
- [ ] PR creata con `Closes #XXX` nella descrizione
- [ ] PR collegata all'issue (dovrebbe apparire automaticamente)
- [ ] Labels aggiunte all'issue
- [ ] Reviewers assegnati (se necessario)

---

## 🔧 Alternativa: Script Automatico

Se preferisci automatizzare, usa lo script PowerShell:

```powershell
.\scripts\ps1\create-issue-pr-req-006.ps1 -GitHubToken "your_github_token"
```

**Per creare il token:**
1. Vai su: https://github.com/settings/tokens
2. "Generate new token (classic)"
3. Permessi: `repo`
4. Copia il token e usalo nello script
