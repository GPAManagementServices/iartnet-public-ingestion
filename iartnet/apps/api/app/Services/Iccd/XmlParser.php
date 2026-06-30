<?php

declare(strict_types=1);

namespace App\Services\Iccd;

use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class XmlParser
{
    /**
     * Parse ICCD XML file and extract schede.
     *
     * @param  string  $xmlPath  Path to XML file
     * @return array{schede: array<int, array>, csm_info: array|null}
     *
     * @throws RuntimeException If parsing fails
     */
    public function parseIccdFile(string $xmlPath): array
    {
        if (! file_exists($xmlPath)) {
            throw new RuntimeException("XML file not found: {$xmlPath}");
        }

        // Load XML with error handling
        libxml_use_internal_errors(true);
        $dom = new DOMDocument();

        // Try to detect encoding from file
        $content = file_get_contents($xmlPath);
        $encoding = $this->detectEncoding($content);

        // Convert to UTF-8 if needed
        if ($encoding !== 'UTF-8' && $encoding !== 'utf-8') {
            $content = mb_convert_encoding($content, 'UTF-8', $encoding);
        }

        if (! $dom->loadXML($content)) {
            $errors = libxml_get_errors();
            libxml_clear_errors();
            $errorMessages = array_map(fn ($error) => trim($error->message), $errors);

            throw new RuntimeException("Failed to parse XML: ".implode('; ', $errorMessages));
        }

        libxml_clear_errors();

        $xpath = new DOMXPath($dom);

        // Extract csm_info if present
        $csmInfo = null;
        $csmInfoNodes = $xpath->query('//csm_info');
        if ($csmInfoNodes->length > 0) {
            $csmInfo = $this->extractCsmInfo($csmInfoNodes->item(0));
        }

        // Extract schede
        $schede = [];
        $schedaNodes = $xpath->query('//scheda');

        foreach ($schedaNodes as $schedaNode) {
            $scheda = $this->extractScheda($schedaNode, $xpath);
            if ($scheda !== null) {
                $schede[] = $scheda;
            }
        }

        Log::info("Parsed ICCD XML file", [
            'file' => basename($xmlPath),
            'schede_count' => count($schede),
            'has_csm_info' => $csmInfo !== null,
        ]);

        return [
            'schede' => $schede,
            'csm_info' => $csmInfo,
        ];
    }

    /**
     * Extract CSM info from XML node.
     *
     * @param  DOMElement  $node  CSM info node
     * @return array
     */
    private function extractCsmInfo(DOMElement $node): array
    {
        $info = [];

        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMElement) {
                $info[$child->nodeName] = $child->textContent;
            }
        }

        return $info;
    }

    /**
     * Extract scheda data from XML node.
     *
     * @param  DOMElement  $schedaNode  Scheda node
     * @param  DOMXPath  $xpath  XPath instance
     * @return array|null  Scheda data or null if invalid
     */
    private function extractScheda(DOMElement $schedaNode, DOMXPath $xpath): ?array
    {
        // Extract scheda ID (usually in an attribute or specific element)
        $schedaId = null;

        // Try to find ID in common locations
        $idNodes = $xpath->query('.//CD', $schedaNode);
        if ($idNodes->length > 0) {
            // Extract text content and normalize (remove extra whitespace and newlines)
            $schedaId = $idNodes->item(0)->textContent;
            // Normalize whitespace: replace newlines and multiple spaces with single space, then trim
            $schedaId = preg_replace('/\s+/', ' ', trim($schedaId));
        }

        if (empty($schedaId)) {
            // Try alternative: look for ID attribute
            if ($schedaNode->hasAttribute('id')) {
                $schedaId = $schedaNode->getAttribute('id');
            }
        }

        if (empty($schedaId)) {
            Log::warning("Scheda without ID found, skipping");
            return null;
        }

        // Extract all key-value pairs from scheda
        $kvPairs = $this->extractKeyValuePairs($schedaNode, $xpath);

        return [
            'id' => $schedaId,
            'kv_pairs' => $kvPairs,
            'xml' => $schedaNode->ownerDocument->saveXML($schedaNode),
        ];
    }

    /**
     * Extract key-value pairs from scheda node.
     * Per elementi che uniscono valori di figli (es. OG/OGT), il valore è costruito
     * unendo i valori dei figli con separatore ';' per evitare concatenazione senza spazio.
     *
     * @param  DOMElement  $node  Node to extract from
     * @param  DOMXPath  $xpath  XPath instance
     * @return array<array{key: string, value: string}>
     */
    private function extractKeyValuePairs(DOMElement $node, DOMXPath $xpath): array
    {
        $pairs = [];

        $allNodes = $xpath->query('.//*', $node);

        foreach ($allNodes as $element) {
            if (! ($element instanceof DOMElement)) {
                continue;
            }

            $path = $this->buildElementPath($element);
            $value = $this->getElementAggregateValue($element);

            if ($value === '') {
                continue;
            }

            $pairs[] = [
                'key' => $path,
                'value' => $value,
            ];
        }

        return $pairs;
    }

    /**
     * Valore per l'elemento: se ha figli elemento con testo, unisce i loro valori con ';'.
     * Altrimenti usa il textContent (evita concatenazione senza separatore nei campi aggregati).
     *
     * @param  DOMElement  $element  Element node
     * @return string
     */
    private function getElementAggregateValue(DOMElement $element): string
    {
        $childValues = [];
        foreach ($element->childNodes as $child) {
            if (! ($child instanceof DOMElement)) {
                continue;
            }
            $v = $this->getElementAggregateValue($child);
            if ($v !== '') {
                $childValues[] = $v;
            }
        }

        if (count($childValues) > 0) {
            return implode(';', $childValues);
        }

        return trim($element->textContent ?? '');
    }

    /**
     * Build element path from root.
     *
     * @param  DOMElement  $element  Element node
     * @return string
     */
    private function buildElementPath(DOMElement $element): string
    {
        $path = [];
        $current = $element;

        while ($current instanceof DOMElement && $current->nodeName !== 'scheda') {
            array_unshift($path, $current->nodeName);
            $current = $current->parentNode;
        }

        return implode('/', $path);
    }

    /**
     * Detect XML encoding from content.
     *
     * @param  string  $content  XML content
     * @return string  Detected encoding
     */
    private function detectEncoding(string $content): string
    {
        // Check XML declaration
        if (preg_match('/<\?xml[^>]+encoding=["\']([^"\']+)["\']/i', $content, $matches)) {
            return strtoupper($matches[1]);
        }

        // Default to ISO-8859-1 as per ICCD spec, fallback to UTF-8
        // As per requirements: "codifica storica ISO-8859-1, in futuro UTF"
        return 'ISO-8859-1';
    }

    /**
     * Parse INFORMA.xml file.
     *
     * @param  string  $xmlPath  Path to INFORMA.xml
     * @return array  Parsed information
     */
    public function parseInforma(string $xmlPath): array
    {
        if (! file_exists($xmlPath)) {
            return [];
        }

        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $content = file_get_contents($xmlPath);
        $encoding = $this->detectEncoding($content);

        if ($encoding !== 'UTF-8' && $encoding !== 'utf-8') {
            $content = mb_convert_encoding($content, 'UTF-8', $encoding);
        }

        if (! $dom->loadXML($content)) {
            libxml_clear_errors();
            return [];
        }

        libxml_clear_errors();

        $xpath = new DOMXPath($dom);
        $info = [];

        // Extract all elements from INFORMA
        $nodes = $xpath->query('//*');

        foreach ($nodes as $node) {
            if ($node instanceof DOMElement && ! empty(trim($node->textContent))) {
                $info[$node->nodeName] = trim($node->textContent);
            }
        }

        return $info;
    }

    /**
     * Parse IMMFTAN.xml file to extract media mappings.
     *
     * @param  string  $xmlPath  Path to IMMFTAN.xml
     * @return array<array{file: string, nctr: string|null, nctn: string|null, rvel: string|null, section: string|null}>  Media mappings
     */
    public function parseImmftan(string $xmlPath): array
    {
        if (! file_exists($xmlPath)) {
            return [];
        }

        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $content = file_get_contents($xmlPath);
        $encoding = $this->detectEncoding($content);

        if ($encoding !== 'UTF-8' && $encoding !== 'utf-8') {
            $content = mb_convert_encoding($content, 'UTF-8', $encoding);
        }

        if (! $dom->loadXML($content)) {
            libxml_clear_errors();
            return [];
        }

        libxml_clear_errors();

        $xpath = new DOMXPath($dom);
        $mappings = [];

        // Extract all <relazione> elements
        // Structure: <identificativo_bene>: nctr, nctn; opzionale <rvel> per disambiguare schede con stesso NCTR/NCTN.
        $relazioni = $xpath->query('//relazione');

        foreach ($relazioni as $relazione) {
            if (! ($relazione instanceof DOMElement)) {
                continue;
            }

            // Extract file name
            $fileNodes = $xpath->query('./file', $relazione);
            $fileName = null;
            if ($fileNodes->length > 0) {
                $fileName = trim($fileNodes->item(0)->textContent);
            }

            if (empty($fileName)) {
                continue;
            }

            // Extract nctr, nctn e opzionale rvel da identificativo_bene
            $nctr = null;
            $nctn = null;
            $rvel = null;
            $identificativoBene = $xpath->query('./identificativo_bene', $relazione);
            if ($identificativoBene->length > 0) {
                $ib = $identificativoBene->item(0);
                $nctrNodes = $xpath->query('./nctr', $ib);
                if ($nctrNodes->length > 0) {
                    $nctr = trim($nctrNodes->item(0)->textContent);
                }

                $nctnNodes = $xpath->query('./nctn', $ib);
                if ($nctnNodes->length > 0) {
                    $nctn = trim($nctnNodes->item(0)->textContent);
                }

                $rvelNodes = $xpath->query('./rvel', $ib);
                if ($rvelNodes->length > 0) {
                    $rvel = trim($rvelNodes->item(0)->textContent);
                }
            }

            // Extract section from identificativo_allegato if needed (optional)
            $section = null;
            $identificativoAllegato = $xpath->query('./identificativo_allegato', $relazione);
            if ($identificativoAllegato->length > 0) {
                // Could extract FTAN or FTAP values if needed
                $section = null; // Leave null for now, can be extended later
            }

            $mappings[] = [
                'file' => $fileName,
                'nctr' => $nctr,
                'nctn' => $nctn,
                'rvel' => $rvel !== null && $rvel !== '' ? $rvel : null,
                'section' => $section,
            ];
        }

        return $mappings;
    }
}
