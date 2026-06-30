# Pre-Commit Hook Configurato

Il pre-commit hook è **già configurato e attivo**. Ogni volta che fai
`git commit`, esegue automaticamente:

1. **Trivy Security Scan** - Verifica vulnerabilità
2. **Local Linting** - Verifica qualità codice (se i tool sono
   installati)

## Stato Attuale

* Pre-commit hook: **CONFIGURATO**
* Security scan (Trivy): **ATTIVO**
* Linting locale: **PARZIALE** (markdownlint installato, altri tool
  opzionali)

## Cosa Succede Quando Committi

```powershell
git commit -m "messaggio"
```

Il hook esegue automaticamente:

1. Security scan → Passa
2. Linting locale → Trova errori (markdownlint ha trovato errori nei
   file .md)

## Installazione Tool (Opzionale ma Consigliata)

Per avere linting completo locale:

```powershell
# PowerShell linter (già installato)
Install-Module -Name PSScriptAnalyzer -Scope CurrentUser -Force

# Altri tool (opzionali)
scoop install shfmt hadolint
npm install -g markdownlint-cli
```

## Gestione Errori

Se il hook trova errori:

**Opzione 1: Correggi gli errori** (raccomandato)

```powershell
# Auto-fix per Markdown
markdownlint -f *.md docs/**/*.md

# Poi committa di nuovo
git commit -m "messaggio"
```

**Opzione 2: Salta il hook** (solo in casi eccezionali)

```powershell
git commit --no-verify -m "messaggio"
```

**Attenzione**: Usa `--no-verify` solo se necessario. Gli errori verranno
comunque rilevati su GitHub Actions.

## Verifica Configurazione

Per verificare che tutto sia configurato:

```powershell
.\scripts\ps1\setup-pre-commit.ps1
```

Questo script mostra:

* Hook configurato correttamente
* Tool installati disponibili
* Tool mancanti (opzionali)

## Prossimi Passi

1. **Correggi gli errori di markdownlint** trovati dal hook
2. **Installa PSScriptAnalyzer** se non è già installato
3. **Committa normalmente** - il hook eseguirà automaticamente tutti i
   check

Il sistema è **già funzionante**.
