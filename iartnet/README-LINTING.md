# 🚀 Linting Locale - Guida Rapida

## Problema Risolto ✅

**Prima**: Dovevi fare commit → push → aspettare GitHub Actions → scoprire errori → correggere → ripetere.

**Ora**: Esegui i linter **localmente prima di committare** e scopri gli errori **immediatamente**!

## Setup Veloce (5 minuti)

### Windows (PowerShell)

```powershell
# Installa i tool necessari
scoop install shfmt hadolint
npm install -g markdownlint-cli
Install-Module -Name PSScriptAnalyzer -Scope CurrentUser

# Setup pre-commit hook (opzionale ma raccomandato)
.\scripts\ps1\setup-pre-commit.ps1
```

### Linux / WSL / macOS

```bash
# Installa i tool necessari
brew install shfmt hadolint  # macOS
npm install -g markdownlint-cli
pip install yamllint

# Setup pre-commit hook (opzionale ma raccomandato)
chmod +x scripts/bash/pre-commit-security.sh
chmod +x scripts/bash/lint-local.sh
```

## Utilizzo

### Metodo 1: Manuale (prima di ogni commit)

**Windows:**
```powershell
.\scripts\ps1\lint-local.ps1
```

**Linux/WSL/macOS:**
```bash
./scripts/bash/lint-local.sh
```

### Metodo 2: Automatico (con pre-commit hook)

Il hook esegue automaticamente linting + security scan prima di ogni commit:

```bash
git commit -m "messaggio"
# Il hook esegue automaticamente:
# 1. Trivy (security scan)
# 2. Linter locali (shfmt, markdownlint, hadolint, PSScriptAnalyzer)
```

## Auto-Fix Disponibili

Alcuni errori possono essere corretti automaticamente:

```bash
# Bash/Shell - Auto-fix indentazione
shfmt -w init.sh
shfmt -w scripts/bash/*.sh

# Markdown - Auto-fix formattazione
markdownlint -f *.md docs/**/*.md
```

## Workflow Consigliato

1. **Modifica il codice**
2. **Esegui linting locale**: `.\scripts\ps1\lint-local.ps1`
3. **Correggi gli errori** mostrati
4. **Riesegui linting** per verificare
5. **Committa** quando tutti i check passano ✅

## Vantaggi

- ✅ **Risparmia tempo**: scopri errori prima di committare
- ✅ **Meno commit**: non serve fare commit multipli per correggere errori
- ✅ **Lavora offline**: non dipendi da GitHub Actions
- ✅ **Feedback immediato**: vedi gli errori in pochi secondi

## Documentazione Completa

Per dettagli completi, vedi: [docs/development/lint-local-setup.md](docs/development/lint-local-setup.md)
