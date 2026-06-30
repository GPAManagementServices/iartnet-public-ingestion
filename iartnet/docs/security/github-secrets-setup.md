# Configurazione GitHub Secrets

## Panoramica

I GitHub Secrets sono variabili d'ambiente criptate che possono essere
utilizzate nei workflow GitHub Actions. Utilizzarli invece di valori
hardcoded migliora significativamente la sicurezza del repository.

## Secrets da Configurare

Per il workflow CI (`ci.yml`), sono necessari i seguenti secrets:

- `POSTGRES_TEST_PASSWORD`: Password per il database di test PostgreSQL
- `POSTGRES_TEST_USER`: Username per il database di test PostgreSQL  
- `POSTGRES_TEST_DB`: Nome del database di test PostgreSQL

## Procedura Passo-Passo

### Metodo 1: Via Interfaccia Web GitHub (Raccomandato)

1. **Accedi a GitHub** e naviga al repository:

   ```text
   https://github.com/GPAManagementServices/iartnet
   ```

2. **Vai alle Impostazioni del Repository**:
   - Clicca su **Settings** (in alto nella barra del repository)
   - Oppure vai direttamente a:

     ```text
     https://github.com/GPAManagementServices/iartnet/settings
     ```

3. **Apri la sezione Secrets**:
   - Nel menu laterale sinistro, cerca **Secrets and variables**
   - Clicca su **Actions**

4. **Aggiungi i Secrets**:
   - Clicca sul pulsante **New repository secret** (in alto a destra)
   - Per ogni secret:
     - **Name**: Inserisci il nome del secret (es. `POSTGRES_TEST_PASSWORD`)
     - **Secret**: Inserisci il valore (non sarà visibile dopo il salvataggio)
     - Clicca **Add secret**

5. **Ripeti per tutti i secrets**:
   - `POSTGRES_TEST_PASSWORD`: Scegli una password sicura (es. generata casualmente)
   - `POSTGRES_TEST_USER`: Username per i test (es. `iartnet_test`)
   - `POSTGRES_TEST_DB`: Nome database test (es. `iartnet_test`)

### Metodo 2: Via GitHub CLI (gh)

Se hai GitHub CLI installato:

```powershell
# Autenticati (se non già fatto)
gh auth login

# Aggiungi i secrets
gh secret set POSTGRES_TEST_PASSWORD --body "your_secure_password_here"
gh secret set POSTGRES_TEST_USER --body "iartnet_test"
gh secret set POSTGRES_TEST_DB --body "iartnet_test"

# Verifica i secrets (solo i nomi, non i valori)
gh secret list
```

### Metodo 3: Via API REST GitHub

```powershell
# Imposta il token GitHub (sostituisci YOUR_TOKEN)
$token = "YOUR_GITHUB_TOKEN"
$headers = @{
    "Authorization" = "token $token"
    "Accept" = "application/vnd.github.v3+json"
}

# Aggiungi POSTGRES_TEST_PASSWORD
$body = @{
    encrypted_value = "your_encrypted_value"  # Deve essere criptato con la chiave pubblica del repo
} | ConvertTo-Json

# Nota: Questo metodo è complesso, meglio usare il Metodo 1 o 2
```

## Valori Consigliati

### POSTGRES_TEST_PASSWORD
- **Lunghezza minima**: 16 caratteri
- **Caratteri**: Lettere maiuscole/minuscole, numeri, simboli
- **Esempio generato**: `TestP@ssw0rd!2024#Secure`
- **Generatore online**: <https://www.lastpass.com/it/features/password-generator>

### POSTGRES_TEST_USER
- **Valore suggerito**: `iartnet_test`
- Deve essere diverso dall'utente di produzione

### POSTGRES_TEST_DB
- **Valore suggerito**: `iartnet_test`
- Deve essere diverso dal database di produzione

## Verifica della Configurazione

### 1. Verifica via GitHub Web Interface
- Vai a: `https://github.com/GPAManagementServices/iartnet/settings/secrets/actions`
- Dovresti vedere i 3 secrets elencati (i valori non sono visibili)

### 2. Verifica via GitHub CLI
```powershell
gh secret list
```

### 3. Test nel Workflow
Dopo aver configurato i secrets, il workflow CI userà automaticamente questi valori invece dei fallback hardcoded.

Per testare:
1. Crea una Pull Request o push su `develop`
2. Il workflow CI si avvierà automaticamente
3. Controlla i log del workflow per verificare che usi i secrets

## Fallback e Compatibilità

Il workflow CI è configurato con valori di fallback, quindi funzionerà anche senza secrets configurati:

```yaml
POSTGRES_DB: ${{ secrets.POSTGRES_TEST_DB || 'iartnet_test' }}
POSTGRES_USER: ${{ secrets.POSTGRES_TEST_USER || 'iartnet' }}
POSTGRES_PASSWORD: ${{ secrets.POSTGRES_TEST_PASSWORD || 'test_password_change_me' }}
```

**⚠️ IMPORTANTE**: I valori di fallback sono solo per sviluppo. In produzione, configura sempre i secrets!

## Best Practices

1. **Non condividere mai i secrets**:
   - Non committare i valori nel codice
   - Non condividerli in chat/email
   - Usa password manager per archiviarli

2. **Ruota i secrets periodicamente**:
   - Cambia le password ogni 90 giorni
   - Aggiorna i secrets su GitHub quando cambi le password

3. **Usa secrets diversi per ambienti diversi**:
   - Test: `POSTGRES_TEST_*`
   - Staging: `POSTGRES_STAGING_*`
   - Production: `POSTGRES_PROD_*`

4. **Limita l'accesso ai secrets**:
   - Solo amministratori del repository dovrebbero poter modificare i secrets
   - Usa GitHub Environments per limitare l'accesso per ambiente

## Troubleshooting

### Secret non trovato nel workflow
- Verifica che il nome del secret sia esatto (case-sensitive)
- Verifica di essere nella sezione corretta (Actions, non Dependabot)
- Verifica i permessi del repository

### Workflow fallisce con errore di autenticazione
- Verifica che i valori dei secrets siano corretti
- Controlla i log del workflow per dettagli specifici

### Come modificare un secret esistente
1. Vai a Settings → Secrets and variables → Actions
2. Clicca sul secret da modificare
3. Clicca **Update** e inserisci il nuovo valore
4. Salva

### Come eliminare un secret
1. Vai a Settings → Secrets and variables → Actions
2. Clicca sul secret da eliminare
3. Clicca **Delete** e conferma

## Riferimenti

- [GitHub Docs: Encrypted Secrets](https://docs.github.com/en/actions/security-guides/encrypted-secrets)
- [GitHub CLI: gh secret](https://cli.github.com/manual/gh_secret)
- [GitHub API: Secrets](https://docs.github.com/en/rest/actions/secrets)
