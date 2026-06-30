# Configurazione Branch Protection su GitHub

## Panoramica

Le Branch Protection Rules proteggono i branch importanti (come `main` e
`develop`) richiedendo che tutte le Pull Request passino determinati controlli
prima di essere mergeate.

## Branch da Proteggere

Per IARTNET, è consigliabile proteggere:

* `main` (o `master`) - Branch di produzione
* `develop` - Branch di sviluppo

## Procedura Passo-Passo

### 1. Accedi alle Impostazioni del Repository

1. Vai al repository su GitHub:

   ```text
   https://github.com/GPAManagementServices/iartnet
   ```

2. Clicca su **Settings** (in alto nella barra del repository)

3. Nel menu laterale sinistro, clicca su **Branches**

4. Oppure vai direttamente a:

   ```text
   https://github.com/GPAManagementServices/iartnet/settings/branches
   ```

### 2. Aggiungi una Branch Protection Rule

1. Nella sezione **Branch protection rules**, clicca su **Add rule** (o **Add
   branch protection rule**)

2. Nel campo **Branch name pattern**, inserisci:

   * Per proteggere `main`: `main`
   * Per proteggere `develop`: `develop`
   * Per proteggere entrambi: `main` e poi crea una regola separata per
     `develop`

### 3. Configura le Protezioni

#### A. Require a pull request before merging

✅ **Attiva questa opzione** (checkbox principale)

Poi configura:

**Require approvals:**

* ✅ Attiva "Require approvals"
* Imposta il numero di approvazioni richieste: **1** (o 2 per maggiore
  sicurezza)
* ✅ (Opzionale) "Dismiss stale pull request approvals when new commits are
  pushed"

**Require review from Code Owners:**

* ✅ (Raccomandato) Attiva se hai un file `CODEOWNERS`

#### B. Require status checks to pass before merging

✅ **Attiva questa opzione**

**Require branches to be up to date before merging:**

* ✅ Attiva questa opzione (importante!)

**Status checks that are required:**

Clicca su "Search for a status check" e aggiungi questi checks:

1. **Standard-Validation** (il job principale del workflow CI)
2. **Super-Linter** (se hai un workflow separato per il linting)
3. **Trivy Security Scan** (dal workflow security.yml)
4. **Lint Code Base** (dal workflow linter.yml)

**Nota**: I nomi esatti dei checks dipendono dai nomi dei job nei tuoi
workflow. Verifica i nomi corretti guardando una PR recente o i workflow.

#### C. Require conversation resolution before merging

✅ (Opzionale ma raccomandato) Attiva questa opzione

* Richiede che tutti i commenti nelle discussioni siano risolti prima del
  merge

#### D. Require signed commits

* ⚠️ (Opzionale) Attiva solo se tutti i contributor hanno commit firmati
* Per ora, lascia disattivato

#### E. Require linear history

* ⚠️ (Opzionale) Attiva se vuoi una storia lineare (no merge commits)
* Per ora, lascia disattivato (permette merge commits)

#### F. Include administrators

✅ **IMPORTANTE**: Attiva questa opzione

* Applica le regole anche agli amministratori del repository
* Garantisce che nessuno possa bypassare le protezioni

#### G. Do not allow bypassing the above settings

✅ **Attiva questa opzione**

* Impedisce a chiunque di bypassare le protezioni, anche agli amministratori

#### H. Restrict who can push to matching branches

* ⚠️ (Opzionale) Attiva se vuoi limitare chi può pushare direttamente
* Se attivato, solo gli utenti/gruppi specificati potranno pushare
* Per ora, lascia disattivato (le PR sono comunque protette)

#### I. Allow force pushes

❌ **DISATTIVA questa opzione** (o lascia disattivata)

* Questo è uno dei requisiti principali: bloccare i force push

#### J. Allow deletions

❌ **DISATTIVA questa opzione**

* Impedisce l'eliminazione accidentale dei branch protetti

### 4. Salva la Configurazione

1. Clicca su **Create** (o **Save changes**)

2. Ripeti il processo per ogni branch da proteggere (`main`, `develop`)

## Configurazione Consigliata per IARTNET

### Per il branch `main`

```text
✅ Require a pull request before merging
   ✅ Require approvals: 1
   ✅ Dismiss stale approvals when new commits are pushed
   
✅ Require status checks to pass before merging
   ✅ Require branches to be up to date before merging
   Status checks required:
   - Standard-Validation
   - Super-Linter (o Lint Code Base)
   - Trivy Security Scan
   
✅ Require conversation resolution before merging

✅ Include administrators

✅ Do not allow bypassing the above settings

❌ Allow force pushes (DISATTIVATO)

❌ Allow deletions (DISATTIVATO)
```

### Per il branch `develop`

```text
✅ Require a pull request before merging
   ✅ Require approvals: 1
   
✅ Require status checks to pass before merging
   ✅ Require branches to be up to date before merging
   Status checks required:
   - Standard-Validation
   - Super-Linter (o Lint Code Base)
   
✅ Include administrators

✅ Do not allow bypassing the above settings

❌ Allow force pushes (DISATTIVATO)

❌ Allow deletions (DISATTIVATO)
```

## Verifica dei Nomi dei Status Checks

Prima di configurare i required status checks, verifica i nomi esatti:

### Metodo 1: Via Interfaccia Web

1. Crea una Pull Request di test
2. Vai alla PR e scorri fino a "Checks" o "Status checks"
3. Nota i nomi esatti dei checks che vengono eseguiti

### Metodo 2: Via Workflow Files

Controlla i nomi dei job nei workflow:

**`.github/workflows/ci.yml`:**

* Job name: `Standard-Validation` o `lint`

**`.github/workflows/linter.yml`:**

* Job name: `lint` o `Lint Code Base`

**`.github/workflows/security.yml`:**

* Job names: `trivy-scan`, `trivy-docker`, `dependency-check`

### Metodo 3: Via GitHub CLI

```powershell
# Lista tutti i checks per una PR
gh pr checks <PR_NUMBER>

# Oppure guarda i workflow runs
gh run list --workflow=ci.yml
```

## Configurazione Avanzata: CODEOWNERS

Per richiedere review da Code Owners, crea un file `.github/CODEOWNERS`:

```gitignore
# Default owners for everything in the repo
* @GPAManagementServices/developers

# Backend (Laravel)
/apps/api/ @GPAManagementServices/backend-team

# Frontend (Nuxt)
/apps/web/ @GPAManagementServices/frontend-team

# Infrastructure
/infra/ @GPAManagementServices/devops-team

# Security and CI/CD
/.github/ @GPAManagementServices/developers
```

Poi nella Branch Protection Rule, attiva:

* ✅ "Require review from Code Owners"

## Troubleshooting

### I status checks non appaiono nella lista

**Problema**: Non vedi i checks nella lista "Search for a status check"

**Soluzione**:

1. Assicurati che i workflow siano stati eseguiti almeno una volta
2. Crea una PR di test e attendi che i workflow completino
3. I checks appariranno dopo la prima esecuzione

### Il merge è bloccato anche se i checks passano

**Problema**: La PR non può essere mergeata anche se tutti i checks sono verdi

**Soluzione**:

1. Verifica che "Require branches to be up to date" sia attivato
2. Se il branch è indietro, fai un rebase o merge del branch base
3. Verifica che tutti i required checks siano effettivamente passati

### Non posso fare force push

**Problema**: Ricevi un errore quando provi a fare force push

**Soluzione**: Questo è il comportamento desiderato! I force push sono
bloccati per proteggere il branch. Usa invece:

* Pull Request per le modifiche
* Rebase normale (non force) se necessario

### Gli amministratori possono ancora bypassare

**Problema**: Gli amministratori possono ancora mergeare senza rispettare le regole

**Soluzione**:

1. Attiva "Include administrators" nella Branch Protection Rule
2. Attiva "Do not allow bypassing the above settings"
3. Verifica che le impostazioni siano salvate correttamente

## Best Practices

1. **Proteggi sempre `main`**: Il branch principale deve sempre essere protetto
2. **Usa almeno 1 approvazione**: Richiedi sempre almeno una review
3. **Richiedi status checks**: Non permettere merge se i test falliscono
4. **Blocca force push**: Sempre, senza eccezioni
5. **Includi amministratori**: Le regole devono applicarsi a tutti
6. **Documenta le regole**: Assicurati che il team conosca le regole

## Verifica della Configurazione

Dopo aver configurato le Branch Protection Rules:

1. **Test con una PR**:

   * Crea un branch di test
   * Fai una modifica
   * Crea una Pull Request verso `main` o `develop`
   * Verifica che:
     * Non puoi mergeare senza approvazione
     * Non puoi mergeare se i checks falliscono
     * Non puoi fare force push al branch protetto

2. **Verifica le impostazioni**:

   * Vai a Settings → Branches
   * Controlla che le regole siano attive
   * Verifica che i required checks siano elencati

## Riferimenti

* [GitHub Docs: About protected branches](https://docs.github.com/en/repositories/configuring-branches-and-merges-in-your-repository/managing-protected-branches/about-protected-branches)
* [GitHub Docs: Requiring status checks](https://docs.github.com/en/repositories/configuring-branches-and-merges-in-your-repository/managing-protected-branches/about-protected-branches#require-status-checks-before-merging)
* [GitHub Docs: CODEOWNERS file](https://docs.github.com/en/repositories/managing-your-repositorys-settings-and-features/customizing-your-repository/about-code-owners)
