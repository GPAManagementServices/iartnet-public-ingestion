# Istruzioni: Creare Issue e PR per REQ-006

## Step 1: Creare GitHub Issue

1. Vai su GitHub: `https://github.com/GPAManagementServices/iartnet/issues/new`

2. **Titolo**:
   ```text
   [REQ-006] Inizializzazione Laravel 12 + Filament 3 Backend
   ```

3. **Corpo dell'Issue** (copia e incolla):

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

4. **Labels**: Aggiungi le label:
   - `requirement`
   - `backend`
   - `laravel`
   - `filament`

5. Clicca su **"Submit new issue"**

6. **Copia il numero dell'issue** (es. #15)

## Step 2: Push del Branch e Creare PR

1. **Push del branch**:
   ```powershell
   git push -u origin feature/req-006-laravel-filament-init
   ```

2. **Crea Pull Request**:
   - Vai su GitHub: `https://github.com/GPAManagementServices/iartnet/compare`
   - Oppure GitHub ti proporrà automaticamente di creare la PR dopo il push

3. **Titolo PR**:
   ```text
   feat: Initialize Laravel 12 + Filament 3 backend (REQ-006)
   ```

4. **Descrizione PR** (copia e incolla, sostituendo #XXX con il numero issue):

```markdown
## Requisito
Closes #XXX (numero issue creato)

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

5. **Collega l'Issue**: Nella descrizione PR, usa `Closes #XXX` o `Fixes #XXX` (sostituisci XXX con il numero issue)

6. **Reviewers**: Assegna i reviewer se necessario

7. Clicca su **"Create pull request"**

## Step 3: Verifica Collegamento

Dopo aver creato la PR:
1. L'issue dovrebbe mostrare "Linked pull requests"
2. La PR dovrebbe mostrare "Closes #XXX"
3. Quando la PR viene merged, l'issue si chiuderà automaticamente

## Note

- Il commit originale (`05e3202`) è già incluso nel branch
- I nuovi file di documentazione sono stati aggiunti
- La PR collega automaticamente l'issue quando viene merged
