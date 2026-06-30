# Setup Linting Locale

## Perché eseguire i linter localmente?

Eseguire i linter localmente **prima di committare** ti permette di:
- ✅ Scoprire errori **immediatamente** invece di aspettare GitHub Actions
- ✅ Risparmiare tempo evitando commit/push multipli
- ✅ Correggere errori prima che vengano tracciati nella storia Git
- ✅ Lavorare offline senza dipendere da GitHub

## Installazione Tool Necessari

### Windows (PowerShell)

```powershell
# 1. shfmt (per Bash/Shell scripts)
scoop install shfmt
# Oppure: winget install shfmt

# 2. markdownlint (per Markdown)
npm install -g markdownlint-cli

# 3. hadolint (per Dockerfile)
scoop install hadolint
# Oppure: winget install hadolint

# 4. PSScriptAnalyzer (per PowerShell)
Install-Module -Name PSScriptAnalyzer -Scope CurrentUser

# 5. yamllint (opzionale, per YAML)
pip install yamllint
```

### Linux / WSL / macOS

```bash
# 1. shfmt (per Bash/Shell scripts)
# macOS
brew install shfmt
# Linux (WSL)
wget -O /usr/local/bin/shfmt https://github.com/mvdan/sh/releases/latest/download/shfmt_v3.7.0_linux_amd64
chmod +x /usr/local/bin/shfmt

# 2. markdownlint (per Markdown)
npm install -g markdownlint-cli

# 3. hadolint (per Dockerfile)
# macOS
brew install hadolint
# Linux (WSL)
wget -O /usr/local/bin/hadolint https://github.com/hadolint/hadolint/releases/latest/download/hadolint-Linux-x86_64
chmod +x /usr/local/bin/hadolint

# 4. yamllint (opzionale, per YAML)
pip install yamllint
```

## Utilizzo

### Eseguire Linting Manualmente

**Windows (PowerShell):**
```powershell
.\scripts\ps1\lint-local.ps1
```

**Linux / WSL / macOS (Bash):**
```bash
chmod +x scripts/bash/lint-local.sh
./scripts/bash/lint-local.sh
```

### Automatico con Pre-Commit Hook

Il pre-commit hook esegue automaticamente:
1. **Trivy** (security scan)
2. **Linter locali** (shfmt, markdownlint, hadolint, PSScriptAnalyzer)

**Setup:**
```powershell
# Windows
.\scripts\ps1\setup-pre-commit.ps1

# Linux/WSL
chmod +x scripts/bash/pre-commit-security.sh
# Il hook è già configurato in .git/hooks/pre-commit
```

**Bypass (non raccomandato):**
```bash
git commit --no-verify
```

## Auto-Fix Disponibili

Alcuni linter possono correggere automaticamente gli errori:

```bash
# Bash/Shell - Auto-fix con shfmt
shfmt -w init.sh
shfmt -w scripts/bash/*.sh

# Markdown - Auto-fix con markdownlint
markdownlint -f *.md docs/**/*.md
```

## Troubleshooting

### "Command not found" errors

Assicurati che i tool siano installati e nel PATH:

```powershell
# Windows - Verifica installazione
Get-Command shfmt, markdownlint, hadolint

# Linux/WSL - Verifica installazione
which shfmt markdownlint hadolint
```

### Pre-commit hook non esegue i linter

Verifica che il hook sia eseguibile:

```bash
chmod +x .git/hooks/pre-commit
chmod +x scripts/bash/lint-local.sh
```

### Voglio saltare il linting per questa commit

```bash
git commit --no-verify -m "messaggio"
```

⚠️ **Attenzione**: Usa `--no-verify` solo in casi eccezionali. Il linting è importante per la qualità del codice.

## Workflow Consigliato

1. **Prima di committare**: Esegui `lint-local.ps1` o `lint-local.sh`
2. **Correggi gli errori** mostrati
3. **Esegui di nuovo** per verificare
4. **Committa** quando tutti i check passano

Questo ti farà risparmiare tempo evitando commit/push multipli!
