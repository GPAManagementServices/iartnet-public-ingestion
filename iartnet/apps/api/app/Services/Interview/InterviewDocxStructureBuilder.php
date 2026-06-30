<?php

declare(strict_types=1);

namespace App\Services\Interview;

use RuntimeException;

/**
 * Costruisce la struttura JSON (campo interviews.ext_json) da paragrafi estratti dai .docx,
 * allineata all'esempio Intervista_tosi.json (header, intervistatore, intervistato, bio, intervista[], archivio_didascalie[]).
 *
 * Domanda/risposta: nel .docx principale ogni blocco domanda è marcato con **(Q)** e la risposta con **(A)**,
 * con spazi opzionali tra parentesi e lettera (es. "( Q )", "( A )").
 *
 * Immagine: paragrafo con tag `Immagine: nomefile.jpg` (nome file caricato in scheda).
 */
final class InterviewDocxStructureBuilder
{
    /**
     * @param  list<string>  $mainParagraphs
     * @param  list<string>  $captionParagraphs
     * @return array{header: string, intervistatore: string, intervistato: string, bio: string, intervista: list<array<string, mixed>>, archivio_didascalie: list<string>}
     */
    public static function buildFromParagraphs(array $mainParagraphs, array $captionParagraphs): array
    {
        if ($mainParagraphs === []) {
            throw new RuntimeException('Il documento principale non contiene testo utilizzabile.');
        }

        $header = self::normalizeHeaderParagraph($mainParagraphs[0]);

        $archivioDidascalie = [];
        foreach ($captionParagraphs as $line) {
            $clean = DocxParagraphExtractor::stripCiteMarkers(trim($line));
            if ($clean !== '') {
                $archivioDidascalie[] = $clean;
            }
        }

        $intervistatore = '';
        $intervistato = '';
        $bioChunks = [];
        $intervista = [];
        $didaIdx = 0;
        $n = count($mainParagraphs);
        $i = 1;
        while ($i < $n) {
            $para = $mainParagraphs[$i];
            if (self::isLabeledFieldParagraph($para, 'Intervistatore')) {
                [$intervistatore, $i] = self::consumeLabeledField($mainParagraphs, $i, 'Intervistatore', $intervistatore);
                continue;
            }
            if (self::isLabeledFieldParagraph($para, 'Intervistato')) {
                [$intervistato, $i] = self::consumeLabeledField($mainParagraphs, $i, 'Intervistato', $intervistato);
                continue;
            }
            if (self::isImagePlaceholder($para)) {
                $intervista[] = self::makeImageBlock($para, $archivioDidascalie, $didaIdx);
                $didaIdx++;
                $i++;
                continue;
            }
            if (self::isDomandaParagraph($para)) {
                [$block, $nextI] = self::consumeDomandaRisposta($mainParagraphs, $i);
                $intervista[] = $block;
                $i = $nextI;
                continue;
            }
            self::appendBioChunk($bioChunks, $para);
            $i++;
        }

        $bio = implode("\n\n", array_values(array_filter($bioChunks, static fn (string $s): bool => trim($s) !== '')));

        $hasDomandaRisposta = false;
        foreach ($intervista as $block) {
            if (($block['tipo'] ?? '') === 'domanda_risposta') {
                $hasDomandaRisposta = true;
                break;
            }
        }
        if (! $hasDomandaRisposta) {
            throw new RuntimeException(
                'Nessun blocco domanda/risposta rilevato nel documento principale. '.
                'Marcare ogni domanda con “(Q)” e ogni risposta con “(A)” (stessa riga o riga successiva al marker).'
            );
        }

        return [
            'header' => $header,
            'intervistatore' => $intervistatore,
            'intervistato' => $intervistato,
            'bio' => $bio,
            'intervista' => $intervista,
            'archivio_didascalie' => $archivioDidascalie,
        ];
    }

    /**
     * JSON ridotto per interviews.ext_json: solo posizione delle immagini rispetto alle coppie domanda/risposta.
     *
     * `dopo_domanda` = numero di coppie Q/A già incontrate nel flusso del documento prima del placeholder immagine
     * (0 = all'inizio; N con N = totale domande = in fondo all'intervista).
     *
     * @param  list<array<string, mixed>>  $intervista
     * @return array{immagini: list<array{indice_immagine: int, dopo_domanda: int, file: string}>}
     */
    public static function buildImagePlacementExtJson(array $intervista): array
    {
        $immagini = [];
        $imageIndex = 0;
        $questionCount = 0;

        foreach ($intervista as $block) {
            $tipo = (string) ($block['tipo'] ?? '');
            if ($tipo === 'inserimento_immagine') {
                $immagini[] = [
                    'indice_immagine' => $imageIndex,
                    'dopo_domanda' => $questionCount,
                    'file' => (string) ($block['file'] ?? ''),
                ];
                $imageIndex++;

                continue;
            }
            if ($tipo === 'domanda_risposta') {
                $questionCount++;
            }
        }

        return ['immagini' => $immagini];
    }

    /**
     * @param  list<array<string, mixed>>  $intervista
     */
    public static function countDomandaRispostaBlocks(array $intervista): int
    {
        $n = 0;
        foreach ($intervista as $block) {
            if (($block['tipo'] ?? '') === 'domanda_risposta') {
                $n++;
            }
        }

        return $n;
    }

    private static function normalizeHeaderParagraph(string $paragraph): string
    {
        $p = trim($paragraph);
        if ($p === '') {
            return $p;
        }
        if (preg_match('/^HEADER:?\s*(.*)$/ius', $p, $m)) {
            return trim((string) $m[1]);
        }

        return $p;
    }

    private static function isLabeledFieldParagraph(string $paragraph, string $label): bool
    {
        $t = trim($paragraph);
        if ($t === '') {
            return false;
        }

        return (bool) preg_match('/^'.preg_quote($label, '/').':?/ius', $t);
    }

    /**
     * @param  list<string>  $paragraphs
     * @return array{0: string, 1: int}
     */
    private static function consumeLabeledField(array $paragraphs, int $startIndex, string $label, string $current): array
    {
        $t = trim($paragraphs[$startIndex]);
        $value = '';
        $nextIndex = $startIndex + 1;

        if (preg_match('/^'.preg_quote($label, '/').':?\s+(.+)$/ius', $t, $m)) {
            $value = trim((string) $m[1]);
        } elseif (preg_match('/^'.preg_quote($label, '/').':?\s*$/ius', $t)) {
            if ($nextIndex < count($paragraphs) && self::isContinuationParagraphForLabeledField($paragraphs[$nextIndex])) {
                $value = trim($paragraphs[$nextIndex]);
                $nextIndex++;
            }
        }

        if ($value === '') {
            return [$current, $nextIndex];
        }

        $merged = $current === '' ? $value : $current."\n\n".$value;

        return [$merged, $nextIndex];
    }

    private static function isContinuationParagraphForLabeledField(string $paragraph): bool
    {
        $t = trim($paragraph);
        if ($t === '') {
            return false;
        }
        if (self::isImagePlaceholder($paragraph) || self::isDomandaParagraph($paragraph)) {
            return false;
        }
        foreach (['Intervistatore', 'Intervistato', 'Bio'] as $label) {
            if (self::isLabeledFieldParagraph($paragraph, $label)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<string>  $chunks
     */
    private static function appendBioChunk(array &$chunks, string $paragraph): void
    {
        $t = trim($paragraph);
        if ($t === '') {
            return;
        }
        if (preg_match('/^Bio:?\s*$/ius', $t)) {
            return;
        }
        if (preg_match('/^Bio:?\s+(.+)$/ius', $t, $m)) {
            $rest = trim((string) $m[1]);
            if ($rest !== '') {
                $chunks[] = $rest;
            }

            return;
        }
        $chunks[] = $t;
    }

    private static function normalizeMarkers(string $text): string
    {
        return str_replace(
            ['（', '）', '［', '］', "\xc2\xa0"],
            ['(', ')', '[', ']', ' '],
            $text
        );
    }

    /**
     * Paragrafo domanda: contiene il marker **(Q)** (variante con spazi: "( Q )").
     */
    public static function isDomandaParagraph(string $text): bool
    {
        $n = self::normalizeMarkers($text);

        return str_contains($n, '(Q)')
            || (bool) preg_match('/\(\s*Q\s*\)/iu', $n);
    }

    /**
     * Estrae il nome file dal tag `Immagine: nomefile.jpg` (paragrafo dedicato).
     */
    public static function parseImageTagFilename(string $text): ?string
    {
        $t = trim($text);
        if ($t === '') {
            return null;
        }
        if (preg_match('/^Immagine:\s*(.+)$/iu', $t, $m)) {
            $file = trim((string) $m[1]);

            return $file !== '' ? $file : null;
        }

        return null;
    }

    private static function isImagePlaceholder(string $text): bool
    {
        return self::parseImageTagFilename($text) !== null;
    }

    /**
     * @param  list<string>  $archivioDidascalie
     */
    private static function makeImageBlock(string $paragraph, array $archivioDidascalie, int $didaIdx): array
    {
        $corr = $archivioDidascalie[$didaIdx] ?? '';
        $file = self::parseImageTagFilename($paragraph) ?? '';

        return [
            'tipo' => 'inserimento_immagine',
            'file' => $file,
            'didascalia_corrispondente' => $corr,
        ];
    }

    /**
     * @param  list<string>  $p
     * @return array{0: array<string, mixed>, 1: int}
     */
    private static function consumeDomandaRisposta(array $p, int $startIndex): array
    {
        $n = count($p);
        $firstPara = $p[$startIndex];
        [$domandaBlock, $inlineRisposta] = self::splitSingleParagraphQuestionAnswer($firstPara);

        $j = $startIndex + 1;
        $rispostaParts = [];
        while ($j < $n && ! self::isImagePlaceholder($p[$j]) && ! self::isDomandaParagraph($p[$j])) {
            $rispostaParts[] = $p[$j];
            $j++;
        }
        $tailRisposta = implode("\n\n", $rispostaParts);
        $rispostaBlock = $inlineRisposta !== ''
            ? ($tailRisposta !== '' ? $inlineRisposta."\n\n".$tailRisposta : $inlineRisposta)
            : $tailRisposta;

        $block = [
            'tipo' => 'domanda_risposta',
            'domanda' => self::splitMarkerAutoreTesto($domandaBlock, 'Q'),
            'risposta' => self::splitMarkerAutoreTesto($rispostaBlock, 'A'),
        ];

        return [$block, $j];
    }

    /**
     * Word può unire domanda e risposta nello stesso paragrafo: "(Q): … (A): …".
     * In tal caso separa prima del primo "(A)" che compare dopo "(Q)".
     *
     * @return array{0: string, 1: string} [blocco domanda (fino a prima di (A)), blocco risposta da (A) in poi; stringa vuota se non c'è (A) nello stesso paragrafo]
     */
    private static function splitSingleParagraphQuestionAnswer(string $text): array
    {
        $n = trim(self::normalizeMarkers($text));
        if ($n === '' || ! preg_match('/\(\s*Q\s*\)/iu', $n)) {
            return [$n, ''];
        }

        $qMatchLen = 0;
        if (preg_match('/\(\s*Q\s*\)\s*:?/iu', $n, $mq, PREG_OFFSET_CAPTURE)) {
            $qMatchLen = $mq[0][1] + strlen($mq[0][0]);
        }
        $afterQ = substr($n, $qMatchLen);
        if (! preg_match('/\(\s*A\s*\)\s*:?/iu', $afterQ, $ma, PREG_OFFSET_CAPTURE)) {
            return [$n, ''];
        }

        $splitPos = $qMatchLen + $ma[0][1];

        return [trim(substr($n, 0, $splitPos)), trim(substr($n, $splitPos))];
    }

    /**
     * Separa autore (testo prima / attorno al marker) e testo domanda/risposta per (Q) o (A).
     *
     * @param  'Q'|'A'  $marker
     * @return array{autore: string, testo: string}
     */
    private static function splitMarkerAutoreTesto(string $block, string $marker): array
    {
        $block = trim($block);
        if ($block === '') {
            return ['autore' => '', 'testo' => ''];
        }

        $token = $marker === 'Q' ? 'Q' : 'A';
        $paren = '\(\s*'.$token.'\s*\)';

        // Prefisso opzionale + (Q)/(A) + ":" opzionale + testo (stessa riga): "(Q): …" o "Luca (Q) …"
        if (preg_match('/^(.*?)('.$paren.'\s*:?)\s*(.*)$/us', $block, $m)) {
            $prefix = trim($m[1]);
            $tag = trim($m[2]);
            $body = trim($m[3]);
            $autore = $prefix === '' ? $tag : $prefix.' '.$tag;

            return ['autore' => $autore, 'testo' => $body];
        }

        $parts = preg_split("/\r\n|\n|\r/", $block, 2);
        if ($parts === false || count($parts) < 2) {
            return ['autore' => '', 'testo' => $block];
        }

        return ['autore' => trim($parts[0]), 'testo' => trim($parts[1])];
    }
}
