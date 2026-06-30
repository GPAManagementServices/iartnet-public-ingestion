# Guida Rapida: Creazione Release v0.1.0-docs

## Opzione 1: Via GitHub Web Interface (Consigliato)

### Step 1: Assicurati che il codice sia su main/develop

Se sei su un branch feature/fix, prima fai merge:

```bash
# Verifica branch corrente
git branch

# Se necessario, merge su develop/main
git checkout develop
git merge fix/ps1-scripts-encoding
git push origin develop
```

### Step 2: Crea Release su GitHub

1. Vai a:
   ```text
   https://github.com/GPAManagementServices/iartnet/releases/new
   ```

2. Compila i campi:

   **Tag version**: `v0.1.0-docs`
   - Seleziona: **Create new tag: v0.1.0-docs on publish**
   - Target: `develop` (o `main` se preferisci)

   **Release title**: `v0.1.0-docs - Documentazione Architettura Completa`

   **Description**: Copia il contenuto da `RELEASE_NOTES_v0.1.0-docs.md`

3. Seleziona **"Set as the latest release"** (se è la prima)

4. Clicca **"Publish release"**

### Step 3: Verifica

Dopo la creazione, verifica:
- Tag creato: `https://github.com/GPAManagementServices/iartnet/tags`
- Release pubblicata: `https://github.com/GPAManagementServices/iartnet/releases`

## Opzione 2: Via Git CLI + GitHub CLI

### Step 1: Crea Tag Locale

```bash
# Assicurati di essere sul branch corretto
git checkout develop  # o main
git pull origin develop

# Crea tag annotato
git tag -a v0.1.0-docs -m "v0.1.0-docs: Documentazione Architettura Completa

- Documentazione architettura completa
- Guida per nuovi developer
- Guida Cursor IDE
- Setup pre-commit hooks
- Configurazione Docker
- Setup sicurezza GitHub Actions"
```

### Step 2: Push Tag

```bash
git push origin v0.1.0-docs
```

### Step 3: Crea Release via GitHub CLI

```bash
gh release create v0.1.0-docs \
  --title "v0.1.0-docs - Documentazione Architettura Completa" \
  --notes-file RELEASE_NOTES_v0.1.0-docs.md \
  --target develop
```

## Opzione 3: Solo Tag (Release Manuale Dopo)

Se vuoi creare solo il tag ora e la release dopo:

```bash
# Crea e push tag
git tag -a v0.1.0-docs -m "v0.1.0-docs: Documentazione Architettura Completa"
git push origin v0.1.0-docs

# Poi crea release manualmente su GitHub quando preferisci
```

## Verifica

Dopo aver creato tag/release:

```bash
# Verifica tag locale
git tag -l

# Verifica tag remoto
git ls-remote --tags origin

# Verifica release (se hai GitHub CLI)
gh release list
```

## Riferimenti nella Documentazione

Dopo aver creato la release, la documentazione in
`docs/architecture/README.md` già contiene riferimenti alla versione
`v0.1.0-docs`. I link funzioneranno automaticamente dopo la creazione della
release.

## Prossimi Passi

Dopo aver creato la release:

1. ✅ Verifica che tag e release siano visibili su GitHub
2. ✅ Procedi con setup GitHub Wiki (vedi `github-wiki-setup.md`)
3. ✅ Linka Wiki alla release creata
