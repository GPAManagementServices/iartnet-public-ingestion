<?php

declare(strict_types=1);

namespace App\Services\Iccd;

use Illuminate\Support\Facades\Log;
use RuntimeException;

class JsonDublinCoreParser
{
    /**
     * Parse JSON file with Dublin Core data and extract records.
     *
     * @param  string  $jsonPath  Path to JSON file
     * @return array<array{record_id: string, title: string, fields: array<array{key: string, value: mixed}>, images: array<array{field: string, data: string, format: string}>}>
     *
     * @throws RuntimeException If parsing fails
     */
    public function parseJsonFile(string $jsonPath): array
    {
        if (! file_exists($jsonPath)) {
            throw new RuntimeException("JSON file not found: {$jsonPath}");
        }

        $content = file_get_contents($jsonPath);
        if ($content === false) {
            throw new RuntimeException("Failed to read JSON file: {$jsonPath}");
        }

        $data = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException("Failed to parse JSON: ".json_last_error_msg());
        }

        // Handle both single object and array of objects
        $records = [];
        if (isset($data[0]) && is_array($data[0])) {
            // Array of records
            $recordsData = $data;
        } else {
            // Single record
            $recordsData = [$data];
        }

        $fileName = basename($jsonPath, '.json');

        foreach ($recordsData as $idx => $recordData) {
            if (! is_array($recordData)) {
                Log::warning("Invalid record data in JSON file", [
                    'file' => $jsonPath,
                    'index' => $idx,
                ]);
                continue;
            }

            // Extract record_id: "Identificatore della risorsa" or filename
            $recordId = $recordData['Identificatore della risorsa'] ?? 
                       $recordData['identificatore della risorsa'] ?? 
                       $recordData['Identificatore'] ?? 
                       $recordData['identificatore'] ?? 
                       null;

            if (empty($recordId)) {
                // Use filename + index as fallback
                $recordId = $fileName.($idx > 0 ? '_'.$idx : '');
            }

            // Extract title: "Titolo"
            $title = $recordData['Titolo'] ?? 
                    $recordData['titolo'] ?? 
                    '';

            // Extract all fields (excluding special image fields)
            $fields = [];
            $images = [];

            foreach ($recordData as $key => $value) {
                // Skip null values
                if ($value === null) {
                    continue;
                }

                // Handle image fields: "Anteprima" with "Formato"
                if (strtolower($key) === 'anteprima' || strtolower($key) === 'preview') {
                    $format = $recordData['Formato'] ?? 
                             $recordData['formato'] ?? 
                             $recordData['Format'] ?? 
                             'jpg'; // Default format

                    // Value can be base64 encoded image data or file path
                    if (is_string($value)) {
                        $images[] = [
                            'field' => $key,
                            'data' => $value,
                            'format' => strtolower($format),
                        ];
                    }
                    continue;
                }

                // Convert value to string for storage
                if (is_array($value)) {
                    $value = json_encode($value, JSON_UNESCAPED_UNICODE);
                } elseif (is_bool($value)) {
                    $value = $value ? 'true' : 'false';
                } else {
                    $value = (string) $value;
                }

                if (!empty($value)) {
                    $fields[] = [
                        'key' => $key,
                        'value' => $value,
                    ];
                }
            }

            $records[] = [
                'record_id' => (string) $recordId,
                'title' => (string) $title,
                'fields' => $fields,
                'images' => $images,
            ];
        }

        Log::info("Parsed JSON Dublin Core file", [
            'file' => basename($jsonPath),
            'records_count' => count($records),
        ]);

        return $records;
    }
}
