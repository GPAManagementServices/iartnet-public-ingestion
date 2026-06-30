# Setup GitHub CLI (gh)

## Installazione su Windows

### Metodo 1: Winget (Raccomandato)

```powershell
winget install --id GitHub.cli
```

### Metodo 2: Chocolatey

```powershell
choco install gh
```

### Metodo 3: Scoop

```powershell
scoop install gh
```

### Metodo 4: Download Manuale

1. Vai a: <https://github.com/cli/cli/releases>
2. Scarica `gh_*_windows_amd64.msi`
3. Esegui l'installer

## Verifica Installazione

```powershell
gh --version
```

## Autenticazione

Dopo l'installazione, autenticati:

```powershell
gh auth login
```

Scegli:

* **GitHub.com**
* **HTTPS** (raccomandato)
* **Yes** per autenticare Git
* **Login with a web browser** (più semplice)

## Comandi Utili per Monitorare Workflow

### Lista ultimi workflow runs

```powershell
gh run list
```

### Lista workflow runs per un branch specifico

```powershell
gh run list --branch fix/ps1-scripts-encoding
```

### Monitora un workflow run in tempo reale

```powershell
# Monitora l'ultimo run
gh run watch

# Monitora un run specifico
gh run watch <RUN_ID>

# Monitora l'ultimo run di un workflow specifico
gh run watch --workflow=ci.yml
```

### Visualizza dettagli di un run

```powershell
gh run view <RUN_ID>
```

### Visualizza log di un run

```powershell
gh run view <RUN_ID> --log
```

### Riexecuta un workflow fallito

```powershell
gh run rerun <RUN_ID>
```

## Esempi Pratici

### Monitorare il workflow CI dopo un push

```powershell
# Push del codice
git push

# Monitora il workflow
gh run watch --workflow=ci.yml
```

### Verificare lo stato di tutti i workflow

```powershell
gh run list --limit 10
```

### Vedere i dettagli dell'ultimo run

```powershell
gh run view $(gh run list --limit 1 --json databaseId --jq '.[0].databaseId')
```

## Alternative se GitHub CLI non è disponibile

### Via Web Browser

1. Vai a: <https://github.com/GPAManagementServices/iartnet/actions>
2. Clicca sul workflow run per vedere i dettagli
3. I log si aggiornano automaticamente

### Via API REST

```powershell
# Lista workflow runs
$headers = @{
    "Accept" = "application/vnd.github.v3+json"
}
$uri = "https://api.github.com/repos/GPAManagementServices/iartnet/actions/runs"
Invoke-RestMethod -Uri $uri -Headers $headers
```

## Riferimenti

* [GitHub CLI Documentation](https://cli.github.com/manual/)
* [GitHub CLI Installation](https://github.com/cli/cli/blob/trunk/docs/install_gh.md#windows)
