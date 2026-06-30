# Screenshot — manuale utente IARTNET

Questa cartella contiene **placeholder SVG** da sostituire con screenshot reali dall'ambiente di test/produzione.

## Come sostituire un placeholder

1. Accedere al pannello `/admin` con dati di esempio.
2. Catturare lo schermo della vista indicata nel titolo del file SVG.
3. Salvare l'immagine con **lo stesso nome** del placeholder, in formato **PNG** o **WebP** (consigliato: PNG per compatibilità).
4. Aggiornare il riferimento nel capitolo Markdown: cambiare `.svg` in `.png` (o `.webp`).

Esempio:

```markdown
![Elenco Institutions](images/01-institutions-list.png)
```

## Convenzione nomi file

| Prefisso | Capitolo |
|----------|----------|
| `01`–`02` | Setup Mirror |
| `03`–`08` | Import su Mirror |
| `09`–`12` | Promozione Master |
| `13`–`15` | Gestione Master |
| `16` | Translation worker |
| `17`–`18` | Interviews (elenco + import) |
| `19` | Salon import |
| `20` | Narrations |

## Risoluzione consigliata

- Larghezza: **1200 px** (rapporto 16:9)
- Formato: PNG, qualità alta
- Evitare dati sensibili nelle capture (mascherare email, ID interni se necessario)
