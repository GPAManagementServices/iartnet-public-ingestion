# Manuale utente IARTNET — Ingestion e Master Data

> **Versione:** bozza v0.2  
> **Riferimenti:** REQ-001 (Master DB), REQ-002 (File-based ingestion), REQ-004 (Multi-tenant)  
> **Pannello:** `/admin` (Filament)

Manuale operativo per chi utilizza il pannello amministrativo IARTNET per importare dati nelle istanze Mirror, promuoverli sul database Master, gestire le schede pubblicate e le funzionalità Master avanzate (traduzione, interviste, salon, narrazioni).

## Indice

| Capitolo | Contenuto |
|----------|-----------|
| [01 — Setup Mirror](01-setup-mirror.md) | Istituzioni, Mirror Instances, data provider |
| [02 — Import su Mirror](02-import-mirror.md) | Pacchetti ICCD/SBN/JSON, campi aggiuntivi (Excel) |
| [03 — Promozione su Master](03-promozione-master.md) | Sincronizzazione dati, campi aggiunti, immagini |
| [04 — Gestione Master](04-gestione-master.md) | Ricerca schede, dettaglio, pubblicazione |
| [05 — Translation](05-translation.md) | Worker traduzione IT → EN (Libre Translate) |
| [06 — Interviews](06-interviews.md) | Elenco INTERVISTA, import DOCX (Step 1 / Step 2) |
| [07 — Salon](07-salon.md) | Import SALON da Excel + ZIP immagini |
| [08 — Narrations](08-narrations.md) | CRUD narrazioni editoriali |

## Prerequisiti

- Accesso al pannello Filament all'indirizzo `/admin` con credenziali valide.
- Ruolo utente adeguato:
  - **admin** o **operatore**: accesso completo alle sezioni operative e, per gli admin, alla configurazione in **Admin**.
  - **partner** (con istituzione associata): accesso limitato alla propria istituzione nei wizard di ingestion.
- Per la sincronizzazione immagini verso Master: variabili d'ambiente `IMAGES_ROOT` e `IIIF_PUBLIC_BASE` configurate correttamente sul server.
- Per import Interview/Salon: variabile `INGEST_FS_ROOT` configurata e scrivibile.
- Per Translation worker: `LIBRE_TRANSLATE_URL`, queue worker e scheduler attivi.

## Panoramica del flusso

```text
Admin: Institution + Mirror Instance
        ↓
Ingestion → Importa Data (ZIP ICCD/SBN/JSON)
        ↓
Ingestion → Mirror Data (revisione record)
        ↓
Ingestion → Add Fields (opzionale, Excel/ZIP)
        ↓
Mirror Data → IMPORT DATA TO MASTER
        ↓
Master → Master Data (consultazione e pubblicazione)
        ↓
Master → Translation (opzionale, IT → EN)
```

**Percorsi Master diretti (senza Mirror):**

```text
Master → Interviews → Import Interview (DOCX + immagini)
Master → Salon (Excel + ZIP immagini)
Master → Narrations (CRUD manuale)
```

## Gruppi di menu nel pannello

| Gruppo | Voci principali | Chi può accedere |
|--------|-----------------|------------------|
| **Admin** | Institutions, Mirror Instances, Users, Roles | Solo **admin** |
| **Ingestion** | Mirror Data, Importa Data, Add Fields, Stats | admin, operatore, partner (scoped) |
| **Master** | Master Data, Translation, Interviews, Salon, Narrations | admin, operatore, partner (scoped) |

## Glossario rapido

| Termine | Significato |
|---------|-------------|
| **Institution** | Ente/istituzione titolare dei dati (tenant). |
| **Mirror Instance** | Istanza logica con schema PostgreSQL dedicato per staging e validazione. |
| **Data Provider** | Standard sorgente: `SIRBEC`, `SIGEC`, `SBN`, `JSON`. |
| **Promoted** | Flag su record Mirror: `No` = non ancora sincronizzato su Master; `Sì` = già promosso. |
| **Stable ID** | Identificativo stabile della scheda nel Master. |
| **Publish State** | Stato pubblicazione scheda Master: `draft` o `published`. |
| **Translated** | Flag scheda Master: traduzione EN completata (`is_translated`). |
| **INTERVISTA** | Tipo scheda intervista; import da DOCX via **Interviews → Import Interview**. |
| **SALON** | Tipo scheda salon/esposizione; import via **Master → Salon**. |
| **Narration** | Contenuto editoriale autonomo in `narrations` (CRUD, non legato a import Mirror). |

## Troubleshooting rapido

| Sintomo | Azione |
|---------|--------|
| Notifica **Formato non accettato** | Verificare contenuto ZIP; formati supportati: ICCD, SBN, JSON. |
| **Schema Mirror o Institution non selezionati** | Completare lo Step 1 del wizard prima di procedere. |
| Import Master **in background** senza contatori | Attendere il worker; controllare i log applicativi. |
| Immagini saltate in sincronizzazione | Verificare che il record esista già su Master e che i file siano nella cartella di extraction. |
| Tabella Master Data vuota | Premere **SEARCH** dopo aver selezionato Institution e CardType. |
| **Translated** resta `No` | Avviare **Translation → Start Translation**; verificare queue worker e `LIBRE_TRANSLATE_URL`. |
| Import Interview Step 2 fallisce | Verificare marker `(Q)`/`(A)` nel DOCX, `INGEST_FS_ROOT`, IIIF se ci sono immagini. |
| Import Salon: immagine mancante | Allineare nomi file colonna C Foglio 2 con i file nello ZIP. |
| **Codice scheda già presente** (Salon/Interview) | Usare uno `stable_id` univoco non ancora presente su Master. |

Per il dettaglio operativo, consultare i capitoli collegati sopra.

## Verifica post-operazione (checklist)

- [ ] Record visibili in **Ingestion → Mirror Data** con colonna **Promoted** = `No` dopo un nuovo import.
- [ ] Dopo promozione metadati: **Promoted** = `Sì` e scheda presente in **Master → Master Data**.
- [ ] Dopo **Synchronize Images**: anteprima immagini disponibile (URL IIIF) in Mirror Data e Master Data.
- [ ] **Publish State** impostato come richiesto (`draft` / `published`).
- [ ] (Se usato) **Translation worker** attivo e **Translated** = **Yes** sulle schede attese.
- [ ] (Interview/Salon) Import Step 1 + Step 2 completati; scheda visibile in **Master Data**.

## Screenshot

Il manuale include **placeholder SVG** in `docs/user/images/` (20 figure). Sostituirli con capture reali seguendo le istruzioni in [images/README.md](images/README.md).

## Note sulla documentazione

- Le etichette UI citate corrispondono al testo attuale del pannello (misto italiano/inglese).
- Questa bozza è derivata dal codice Filament in `apps/api/app/Filament/`; aggiornare il manuale quando cambiano label o flussi.
- Per dettagli tecnici su mapping e servizi: `apps/api/app/Services/Import/README.md`.
