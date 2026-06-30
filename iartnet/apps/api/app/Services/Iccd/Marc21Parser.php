<?php

declare(strict_types=1);

namespace App\Services\Iccd;

use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class Marc21Parser
{
    /**
     * Parse MARC21 XML file and extract records.
     *
     * @param  string  $xmlPath  Path to MARC21 XML file
     * @return array<array{record_id: string, leader: string, fields: array<array{tag: string, value: string}>}>
     *
     * @throws RuntimeException If parsing fails
     */
    public function parseMarc21File(string $xmlPath): array
    {
        if (! file_exists($xmlPath)) {
            throw new RuntimeException("XML file not found: {$xmlPath}");
        }

        libxml_use_internal_errors(true);
        $dom = new DOMDocument();

        $content = file_get_contents($xmlPath);
        if ($content === false) {
            throw new RuntimeException("Failed to read XML file: {$xmlPath}");
        }

        if (! $dom->loadXML($content)) {
            $errors = libxml_get_errors();
            libxml_clear_errors();
            $errorMessages = array_map(fn ($error) => trim($error->message), $errors);

            throw new RuntimeException("Failed to parse MARC21 XML: ".implode('; ', $errorMessages));
        }

        libxml_clear_errors();

        $xpath = new DOMXPath($dom);
        // Register MARC namespace
        $xpath->registerNamespace('marc', 'http://www.loc.gov/MARC21/slim');

        $records = [];

        // Extract all records
        $recordNodes = $xpath->query('//marc:record');

        if ($recordNodes->length === 0) {
            Log::warning("MARC21 XML file contains no records", [
                'file' => basename($xmlPath),
                'full_path' => $xmlPath,
            ]);
            return [];
        }

        Log::info("MARC21 XML file: records found", [
            'file' => basename($xmlPath),
            'records_count' => $recordNodes->length,
        ]);

        foreach ($recordNodes as $recordNode) {
            if (! ($recordNode instanceof DOMElement)) {
                continue;
            }

            // Extract controlfield tag="001" as record_id
            $controlField001 = $xpath->query('.//marc:controlfield[@tag="001"]', $recordNode);
            $recordId = null;
            if ($controlField001->length > 0) {
                $recordId = trim($controlField001->item(0)->textContent);
            }

            if (empty($recordId)) {
                Log::warning("MARC21 record without controlfield 001 found, skipping", [
                    'file' => basename($xmlPath),
                    'record_index' => count($records),
                ]);
                continue;
            }

            // Extract leader
            $leaderNodes = $xpath->query('.//marc:leader', $recordNode);
            $leader = '';
            if ($leaderNodes->length > 0) {
                $leader = trim($leaderNodes->item(0)->textContent);
            }

            // Extract all fields (controlfields and datafields)
            $fields = [];
            
            // Control fields
            $controlFields = $xpath->query('.//marc:controlfield', $recordNode);
            foreach ($controlFields as $controlField) {
                if (! ($controlField instanceof DOMElement)) {
                    continue;
                }
                $tag = $controlField->getAttribute('tag');
                $value = trim($controlField->textContent);
                if (!empty($tag) && !empty($value)) {
                    $fields[] = [
                        'tag' => $tag,
                        'value' => $value,
                    ];
                }
            }

            // Data fields
            $dataFields = $xpath->query('.//marc:datafield', $recordNode);
            foreach ($dataFields as $dataField) {
                if (! ($dataField instanceof DOMElement)) {
                    continue;
                }
                $tag = $dataField->getAttribute('tag');
                $ind1 = $dataField->getAttribute('ind1');
                $ind2 = $dataField->getAttribute('ind2');
                
                // Extract subfields
                $subfields = $xpath->query('.//marc:subfield', $dataField);
                $subfieldValues = [];
                foreach ($subfields as $subfield) {
                    if (! ($subfield instanceof DOMElement)) {
                        continue;
                    }
                    $code = $subfield->getAttribute('code');
                    $value = trim($subfield->textContent);
                    if (!empty($code) && !empty($value)) {
                        $subfieldValues[] = $code.': '.$value;
                    }
                }
                
                $value = implode(' | ', $subfieldValues);
                if (!empty($tag) && !empty($value)) {
                    $fields[] = [
                        'tag' => $tag,
                        'value' => $value,
                        'ind1' => $ind1,
                        'ind2' => $ind2,
                    ];
                }
            }

            $records[] = [
                'record_id' => $recordId,
                'leader' => $leader,
                'fields' => $fields,
            ];
        }

        Log::info("Parsed MARC21 XML file", [
            'file' => basename($xmlPath),
            'records_count' => count($records),
        ]);

        return $records;
    }
}
