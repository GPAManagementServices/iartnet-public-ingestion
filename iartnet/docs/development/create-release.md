# Creazione Release e Tag GitHub

## Panoramica

Prima di pubblicare la documentazione nella Wiki, è importante creare una
**Release** e un **Tag** su GitHub per versionare lo stato attuale del
repository. Questo permette di:

* Riferirsi a una versione specifica nella documentazione
* Mantenere traccia delle milestone del progetto
* Permettere rollback se necessario
* Collegare la Wiki a una versione specifica

## Convenzione Versioning

Per IARTNET, usiamo **Semantic Versioning** (SemVer):

```text
MAJOR.MINOR.PATCH
```

* **MAJOR**: Cambiamenti breaking (es. v2.0.0)
* **MINOR**: Nuove funzionalità compatibili (es. v1.1.0)
* **PATCH**: Bugfix e correzioni (es. v1.0.1)

**Per documentazione/architettura**:
* Usa `v0.1.0` per prima release documentazione
* Usa `v0.2.0` per aggiornamenti significativi documentazione
* Usa `v1.0.0` quando il progetto è production-ready

## Procedura: Creazione Tag e Release

### Metodo 1: Via GitHub Web Interface (Consigliato)

#### Step 1: Prepara il Repository

Assicurati che tutti i cambiamenti siano committati e pushati:

```bash
git add .
git commit -m "docs: aggiunta documentazione architettura completa"
git push origin fix/ps1-scripts-encoding
```

#### Step 2: Merge su Branch Principale

Se necessario, crea PR e merge su `main` o `develop`:

```bash
# Dopo merge su main/develop
git checkout main
git pull origin main
```

#### Step 3: Crea Release su GitHub

1. Vai a: `https://github.com/GPAManagementServices/iartnet/releases`
2. Clicca su **"Create a new release"**
3. Compila i campi:

   **Tag version**: `v0.1.0-docs`
   - Oppure: `v0.1.0` (se preferisci semver standard)
   - Scegli: **Create new tag: v0.1.0-docs on publish**

   **Release title**: `v0.1.0-docs - Documentazione Architettura Completa`

   **Description**:
   ```markdown
   ## 🎉 Prima Release Documentazione

   Questa release segna il completamento della documentazione architetturale
   completa del progetto IARTNET.

   ### ✨ Novità

   * 📚 Documentazione architettura completa (`docs/architecture/README.md`)
   * 📖 Guida per nuovi developer con step-by-step
   * 🎯 Guida specifica per developers che usano Cursor
   * 🔧 Setup pre-commit hooks (security + linting)
   * 🐳 Configurazione Docker completa
   * 🔒 Setup sicurezza (GitHub Actions, Trivy, Dependabot)
   * 📝 Guida integrazione GitHub Wiki

   ### 📋 Componenti Documentati

   * Architettura Filesystem (Monorepo)
   * Architettura GitHub (CI/CD, Security)
   * Architettura Docker (Compose, Dockerfile)
   * Setup iniziale per nuovi developer
   * Workflow sviluppo con Cursor IDE
   * Troubleshooting comune

   ### 🔗 Link Utili

   * [Documentazione Architettura](../blob/main/docs/architecture/README.md)
   * [Getting Started](../blob/main/README.md#getting-started)
   * [Local Development](../blob/main/docs/runbooks/local-dev.md)

   ### 📦 File Principali

   * `docs/architecture/README.md` - Architettura completa
   * `docs/development/github-wiki-setup.md` - Setup Wiki
   * `docs/development/create-release.md` - Questa guida
   ```

4. Seleziona **"Set as the latest release"** (se è la prima)
5. Clicca **"Publish release"**

### Metodo 2: Via Git CLI

#### Step 1: Crea Tag Locale

```bash
# Assicurati di essere sul branch corretto e aggiornato
git checkout main  # o develop
git pull origin main

# Crea tag annotato (consigliato)
git tag -a v0.1.0-docs -m "v0.1.0-docs: Documentazione Architettura Completa

- Documentazione architettura completa
- Guida per nuovi developer
- Guida Cursor IDE
- Setup pre-commit hooks
- Configurazione Docker
- Setup sicurezza GitHub Actions"

# Oppure tag semplice
git tag v0.1.0-docs
```

#### Step 2: Push Tag

```bash
# Push singolo tag
git push origin v0.1.0-docs

# Oppure push tutti i tag
git push origin --tags
```

#### Step 3: Crea Release via GitHub CLI

```bash
# Se hai GitHub CLI installato
gh release create v0.1.0-docs \
  --title "v0.1.0-docs - Documentazione Architettura Completa" \
  --notes-file RELEASE_NOTES.md \
  --target main
```

Oppure crea la release manualmente su GitHub dopo aver pushato il tag.

## Verifica Release

Dopo la creazione, verifica:

1. **Tag creato**: `https://github.com/GPAManagementServices/iartnet/tags`
2. **Release pubblicata**: `https://github.com/GPAManagementServices/iartnet/releases`
3. **Tag nel repository**: `git tag -l` (locale)

## Riferimenti nella Documentazione

Dopo aver creato la release, aggiorna i riferimenti nella documentazione:

### Nel README.md

```markdown
## Version

**Current Release**: [v0.1.0-docs](https://github.com/GPAManagementServices/iartnet/releases/tag/v0.1.0-docs)

Questa documentazione si riferisce alla versione **v0.1.0-docs** del progetto.
```

### Nella Wiki

Quando crei la Wiki, riferisciti alla release:

```markdown
# Architettura IARTNET

> **Versione Documentazione**: [v0.1.0-docs](https://github.com/GPAManagementServices/iartnet/releases/tag/v0.1.0-docs)
>
> **Data Release**: 2026-01-11
>
> Questa documentazione descrive l'architettura del progetto IARTNET alla
> versione v0.1.0-docs.
```

### Link Specifici

Per linkare a file specifici in una release:

```markdown
[Architettura v0.1.0-docs](https://github.com/GPAManagementServices/iartnet/blob/v0.1.0-docs/docs/architecture/README.md)
```

## Best Practices

### 1. Naming Convention Tag

* Usa prefisso `v` (es. `v0.1.0`)
* Usa suffisso descrittivo se necessario (es. `v0.1.0-docs`)
* Evita spazi nel nome tag
* Usa lowercase e trattini

### 2. Release Notes

Sempre includere:
* Cosa è cambiato
* Perché è cambiato
* Link a documentazione
* Breaking changes (se presenti)

### 3. Versionamento Documentazione

* Crea nuova release quando:
  * Aggiungi sezioni significative
  * Modifichi architettura documentata
  * Aggiorni procedure setup
* Usa PATCH per correzioni minori (es. `v0.1.1`)
* Usa MINOR per aggiunte (es. `v0.2.0`)

## Automazione

### GitHub Action per Auto-Release

Crea `.github/workflows/release.yml`:

```yaml
---
name: Create Release

on:
  push:
    tags:
      - 'v*'

jobs:
  release:
    runs-on: ubuntu-latest
    steps:
      - name: Checkout
        uses: actions/checkout@v4.1.1

      - name: Create Release
        uses: actions/create-release@v1
        env:
          GITHUB_TOKEN: ${{ secrets.GITHUB_TOKEN }}
        with:
          tag_name: ${{ github.ref }}
          release_name: Release ${{ github.ref }}
          body_path: RELEASE_NOTES.md
          draft: false
          prerelease: false
```

## Troubleshooting

### Tag già esiste

**Errore**: `fatal: tag 'v0.1.0-docs' already exists`

**Soluzione**:
```bash
# Elimina tag locale
git tag -d v0.1.0-docs

# Elimina tag remoto
git push origin --delete v0.1.0-docs

# Ricrea tag
git tag -a v0.1.0-docs -m "Nuovo messaggio"
git push origin v0.1.0-docs
```

### Tag non visibile su GitHub

**Problema**: Tag creato localmente ma non visibile su GitHub

**Soluzione**:
```bash
# Verifica tag locale
git tag -l

# Push esplicito
git push origin v0.1.0-docs

# Verifica su GitHub
gh release list
```

## Prossimi Passi

Dopo aver creato la release:

1. ✅ Verifica che tag e release siano visibili su GitHub
2. ✅ Aggiorna documentazione con riferimenti alla versione
3. ✅ Procedi con setup GitHub Wiki (vedi `github-wiki-setup.md`)
4. ✅ Linka Wiki alla release creata
