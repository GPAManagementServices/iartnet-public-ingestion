# Generatore Stories

Editor React per file JSON delle **Stories** IARTNET (`TStoriesTypeData` / `ext_json`).

## Modello sezioni

Ogni elemento di `ext_json.sections` è un **`TStorySection`**: unione discriminata su `Kind`, con campi comuni ereditati da **`TStorySectionBase`**.

### Campi comuni (`TStorySectionBase`)

| Campo | Tipo | Note |
|-------|------|------|
| `Kind` | `SectionKind` | **Obbligatorio** su file e in memoria; distingue i 8 tipi di sezione |
| `published` | `boolean` | Visibilità della sezione; default `true` se assente nel JSON |
| `animazione` | `{ Effetto: string }` | Effetto animazione; default `{ Effetto: '' }` se assente |

I tipi specifici (`TStoryTextIntroType`, `TStorySplitImageType`, …) estendono il base e restringono `Kind` al letterale corrispondente (es. `'TextIntro'`, `'SplitImage'`).

`TextIntro` e `InlineText` condividono la stessa forma payload (`{ Text }`); sono distinti solo da `Kind`.

### Tipi di sezione (`SectionKind`)

`TextIntro` · `InlineText` · `SplitContent` · `SplitImage` · `ScrollReveal` · `InlineImage` · `ImageFullScreen` · `IIFAnnotationsGroup`

### Parse e export

- **`Kind` obbligatorio**: sezione senza `Kind` → errore di parse (`Kind mancante`).
- **Nessuna inferenza strutturale**: il tipo non si deduce più dalla forma dell’oggetto; vale solo `Kind` esplicito.
- **Import legacy**: JSON esistenti con solo `Kind` + payload continuano ad aprirsi; `published` e `animazione` ricevono i default in memoria.
- **Export**: ogni sezione serializzata include sempre `Kind`, `published` e `animazione` (oltre ai campi specifici del tipo).

### Editor (tab Contenuti)

Per ogni sezione, sopra i campi di contenuto:

- checkbox **Pubblicata** (`published`)
- input **Effetto animazione** (`animazione.Effetto`)

Il dropdown tipo aggiorna `section.Kind`; al cambio template il payload viene resettato ma **`published`** e **`animazione`** restano invariati.

### Moduli principali (sezioni)

| Modulo | Ruolo |
|--------|--------|
| `src/types/story.ts` | `TStorySectionBase`, `TStoryAnimazione`, tipi sezione, unione `TStorySection` |
| `src/story/sectionBase.ts` | Default e `normalizeSectionBase` in lettura |
| `src/story/sectionWire.ts` | `parseSectionFromWire`, `normalizeSection` (validazione per `Kind`) |
| `src/story/sectionKind.ts` | `createEmptySection`, `changeSectionKind`, etichette UI |
| `src/story/sectionRow.ts` | Righe editor: `{ id, section }` (`Kind` dentro `section`) |
| `src/components/SectionBaseFields.tsx` | Form campi base in UI |

### Test fixture

`src/story/storiesFixtures.test.ts` verifica parse e round-trip su tutti i file in `Stories/*.json`.

## Sviluppo

```bash
npm install
npm run dev
npm test
npm run build
```

## Testo ricco (HTML)

I campi testuali delle sezioni sono **`string` HTML** (non più `string[]`). Il viewer Vue downstream interpreta il markup.

### Campi con editor WYSIWYG

| Sezione | Campi |
|---------|--------|
| TextIntro / InlineText | `Text` |
| SplitContent | `LeftText`, `RightText` |
| SplitImage / ScrollReveal | `Text` |
| IIFAnnotationsGroup | `Caption`, `Annotations[].Text` |

Ogni editor include toolbar (grassetto, corsivo, link) e toggle **Sorgente HTML** per vedere/modificare il markup.

### Tag ammessi

`b`, `strong`, `i`, `em`, `a` (con `href`, `target`, `rel`), `p`, `br`

Solo **Caption IIIF** e **Text annotazioni** (`IIFAnnotationsGroup`): anche `img` con `src` http/https (inserimento via toolbar → prompt URL).

L’HTML in ingresso e in uscita passa da sanitizzazione (DOMPurify).

### Import legacy

All’**apertura** o **Applica JSON** di un file con formato precedente:

- `string[]` → elementi uniti con `<br />` (stringhe vuote = spaziatura)
- plain `string` con `\n` → `<br />`
- HTML esistente → preservato e normalizzato

Non c’è migrazione batch sui file in repo: ogni file viene normalizzato al caricamento. **Salva su file** e **Sincronizza testo dall’editor** producono sempre `string` HTML (mai `string[]` sui campi sopra).

### Moduli principali

- `src/story/richText.ts` — normalizzazione e anteprima plain
- `src/story/richTextSanitize.ts` — whitelist tag
- `src/story/sectionRichText.ts` — hook in parse sezioni
- `src/story/storyExport.ts` — normalizzazione pre-serializzazione JSON
- `src/components/fields/RichTextField.tsx` — editor TipTap

## Verifica manuale

1. Caricare un JSON legacy (mix `string[]` / HTML / plain text; ogni sezione deve avere `Kind`)
2. Tab **Contenuti** → verificare **Pubblicata** / **Effetto animazione** e editor Caption IIIF
3. **Sincronizza testo dall’editor** → controllare che `Text`, `Caption`, ecc. siano stringhe HTML
4. **Salva su file** → ogni sezione con `Kind`, `published`, `animazione`; nessun array sui campi testo ricco
5. Verificare rendering nel viewer Vue
