<?php

declare(strict_types=1);

namespace App\Services\Interview;

use DOMDocument;
use DOMXPath;
use RuntimeException;
use ZipArchive;

/**
 * Estrae i testi dei paragrafi da un file .docx (WordprocessingML).
 */
final class DocxParagraphExtractor
{
    /**
     * @return list<string> Paragrafi non vuoti, in ordine, senza marcatori [cite: n]
     */
    public static function extractParagraphs(string $absolutePath): array
    {
        if (! is_file($absolutePath)) {
            throw new RuntimeException("File DOCX non trovato: {$absolutePath}");
        }

        $zip = new ZipArchive();
        if ($zip->open($absolutePath) !== true) {
            throw new RuntimeException("Impossibile aprire il DOCX: {$absolutePath}");
        }
        $xml = $zip->getFromName('word/document.xml');
        $zip->close();

        if ($xml === false) {
            throw new RuntimeException('DOCX non valido: manca word/document.xml');
        }

        $dom = new DOMDocument();
        if (@$dom->loadXML($xml) === false) {
            throw new RuntimeException('DOCX non valido: XML non leggibile');
        }

        $xp = new DOMXPath($dom);
        $xp->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');

        $paragraphs = [];
        foreach ($xp->query('//w:p') as $p) {
            $text = '';
            foreach ($xp->query('.//w:t', $p) as $t) {
                $text .= $t->textContent;
            }
            $text = trim($text);
            if ($text === '') {
                continue;
            }
            $paragraphs[] = self::stripCiteMarkers($text);
        }

        return $paragraphs;
    }

    public static function stripCiteMarkers(string $text): string
    {
        return trim(preg_replace('/\s*\[cite:\s*\d+]/u', '', $text) ?? $text);
    }
}
