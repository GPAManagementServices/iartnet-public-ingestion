<?php

declare(strict_types=1);

namespace App\Services\Interview;

use App\Services\Iiif\IiifImageService;
use App\Support\IngestionPaths;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Crea record Master + record_kv + i18n_texts + web_resources + riga interviews da materiali già caricati sotto INGEST_FS_ROOT.
 */
final class InterviewMasterImportService
{
    private const MASTER_SCHEMA = 'iartnet_master';

    /**
     * @return array{record_id: string, interview_id: string}
     */
    public function importFromPreparedRun(
        string $stableId,
        string $institutionId,
        string $ingestRunId
    ): array {
        $stableId = trim($stableId);
        if ($stableId === '') {
            throw new RuntimeException('Il codice scheda (stable_id) è obbligatorio.');
        }

        $exists = DB::connection('pgsql')->table(self::MASTER_SCHEMA.'.records')
            ->where('stable_id', $stableId)
            ->exists();
        if ($exists) {
            throw new RuntimeException("Esiste già una scheda con codice (stable_id) '{$stableId}'.");
        }

        $runDir = IngestionPaths::interviewImportRunRoot($ingestRunId);
        if (! is_dir($runDir)) {
            throw new RuntimeException('Cartella import non trovata. Ripetere lo step di upload.');
        }

        $mainPath = $runDir.DIRECTORY_SEPARATOR.'main.docx';
        $captionsPath = $runDir.DIRECTORY_SEPARATOR.'didascalie.docx';
        if (! is_file($mainPath) || ! is_file($captionsPath)) {
            throw new RuntimeException('File principale o didascalie mancanti nella cartella di import.');
        }

        $mainParas = DocxParagraphExtractor::extractParagraphs($mainPath);
        $captionParas = DocxParagraphExtractor::extractParagraphs($captionsPath);
        $extData = InterviewDocxStructureBuilder::buildFromParagraphs($mainParas, $captionParas);

        $imagePaths = $this->collectUploadedJpegs($runDir);

        $iiifPublicBase = rtrim((string) config('services.iiif.public_base', env('IIIF_PUBLIC_BASE', '')), '/');
        if ($imagePaths !== [] && $iiifPublicBase === '') {
            throw new RuntimeException('IIIF_PUBLIC_BASE non configurato: necessario per registrare le immagini.');
        }

        $imagesRoot = rtrim((string) config('images.root', env('IMAGES_ROOT', '')), DIRECTORY_SEPARATOR);
        if ($imagePaths !== [] && ($imagesRoot === '' || ! is_dir($imagesRoot) || ! is_writable($imagesRoot))) {
            throw new RuntimeException('IMAGES_ROOT non configurato o non scrivibile.');
        }

        $iiifService = null;
        if ($imagePaths !== []) {
            try {
                $iiifService = new IiifImageService();
            } catch (\Throwable $e) {
                throw new RuntimeException('Servizio IIIF non disponibile: '.$e->getMessage(), 0, $e);
            }
        }

        return DB::connection('pgsql')->transaction(function () use (
            $stableId,
            $institutionId,
            $extData,
            $imagePaths,
            $ingestRunId,
            $iiifService,
            $iiifPublicBase,
            $imagesRoot
        ): array {
            $recordId = (string) Str::uuid();
            $now = now();

            DB::table(self::MASTER_SCHEMA.'.records')->insert([
                'id' => $recordId,
                'stable_id' => $stableId,
                'primary_institution_id' => $institutionId,
                'edm_type' => 'TEXT',
                'publish_state' => 'draft',
                'primary_lang' => 'it',
                'ext_json' => '{}',
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $this->upsertRecordKvAndI18n($recordId, $extData);

            $ord = 0;
            foreach ($imagePaths as $srcPath) {
                $ord++;
                $this->importOneImage(
                    $recordId,
                    $srcPath,
                    $imagesRoot,
                    $iiifPublicBase,
                    $iiifService,
                    $ingestRunId,
                    $ord
                );
            }

            $header = (string) ($extData['header'] ?? '');
            $name = mb_substr($header !== '' ? $header : $stableId, 0, 255);

            $interviewId = (string) Str::uuid();
            $placementExtJson = InterviewDocxStructureBuilder::buildImagePlacementExtJson(
                $extData['intervista'] ?? []
            );
            DB::table(self::MASTER_SCHEMA.'.interviews')->insert([
                'id' => $interviewId,
                'record_id' => $recordId,
                'name' => $name,
                'description' => null,
                'ext_json' => json_encode($placementExtJson, JSON_UNESCAPED_UNICODE),
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return [
                'record_id' => $recordId,
                'interview_id' => $interviewId,
            ];
        });
    }

    /**
     * @param  array{header: string, intervistatore: string, intervistato: string, bio: string, intervista: list<array<string, mixed>>, archivio_didascalie: list<string>}  $extData
     */
    private function upsertRecordKvAndI18n(string $recordId, array $extData): void
    {
        $meta = [
            'source_standard' => 'INTERVIEW_IMPORT',
            'source_field' => 'interview_docx',
            'import_process' => 'interview_to_master',
        ];

        $questionCount = InterviewDocxStructureBuilder::countDomandaRispostaBlocks(
            $extData['intervista'] ?? []
        );

        $pairs = [
            ['key' => 'card_type', 'value' => 'INTERVISTA'],
            ['key' => 'header', 'value' => (string) ($extData['header'] ?? '')],
            ['key' => 'intervistatore', 'value' => (string) ($extData['intervistatore'] ?? '')],
            ['key' => 'intervistato', 'value' => (string) ($extData['intervistato'] ?? '')],
            ['key' => 'bio', 'value' => (string) ($extData['bio'] ?? '')],
            ['key' => 'QuestionsNumber', 'value' => (string) $questionCount, 'datatype' => 'number'],
        ];

        $qi = 0;
        foreach ($extData['intervista'] ?? [] as $block) {
            if (($block['tipo'] ?? '') !== 'domanda_risposta') {
                continue;
            }
            $dom = $block['domanda'] ?? [];
            $ris = $block['risposta'] ?? [];
            $domStr = $this->formatAutoreTesto(is_array($dom) ? $dom : []);
            $risStr = $this->formatAutoreTesto(is_array($ris) ? $ris : []);
            $pairs[] = ['key' => 'domanda_'.$qi, 'value' => $domStr];
            $pairs[] = ['key' => 'risposta_'.$qi, 'value' => $risStr];
            $qi++;
        }

        $dj = 0;
        foreach ($extData['archivio_didascalie'] ?? [] as $cap) {
            $pairs[] = ['key' => 'didascalia_'.$dj, 'value' => (string) $cap];
            $dj++;
        }

        foreach ($pairs as $row) {
            $key = $row['key'];
            $valueText = $row['value'];
            $datatype = (string) ($row['datatype'] ?? 'string');
            $kvId = (string) Str::uuid();
            $extJson = json_encode($meta, JSON_UNESCAPED_UNICODE);

            DB::table(self::MASTER_SCHEMA.'.record_kv')->insert([
                'id' => $kvId,
                'record_id' => $recordId,
                'key' => $key,
                'datatype' => $datatype,
                'value_text' => $valueText,
                'value_uri' => null,
                'value_json' => null,
                'display_order' => 0,
                'origin' => 'authoritative',
                'ext_json' => $extJson,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->insertI18nEn($recordId, $key, $valueText);
        }
    }

    /**
     * @param  array{autore?: string, testo?: string}  $parts
     */
    private function formatAutoreTesto(array $parts): string
    {
        $a = trim((string) ($parts['autore'] ?? ''));
        $t = trim((string) ($parts['testo'] ?? ''));
        if ($a === '') {
            return $t;
        }
        if ($t === '') {
            return $a;
        }

        return $a."\n\n".$t;
    }

    private function insertI18nEn(string $recordId, string $fieldName, string $textValue): void
    {
        if ($textValue === '') {
            return;
        }

        DB::table(self::MASTER_SCHEMA.'.i18n_texts')->insert([
            'id' => (string) Str::uuid(),
            'entity_type' => 'record',
            'entity_id' => $recordId,
            'field_name' => $fieldName,
            'lang' => 'en',
            'text_value' => $textValue,
            'origin' => 'authoritative',
            'status' => 'draft',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @return list<string> Path assoluti ordinati
     */
    private function collectUploadedJpegs(string $runDir): array
    {
        $imgDir = $runDir.DIRECTORY_SEPARATOR.'images';
        if (! is_dir($imgDir)) {
            return [];
        }
        $paths = [];
        foreach (glob($imgDir.DIRECTORY_SEPARATOR.'*') ?: [] as $path) {
            if (! is_file($path)) {
                continue;
            }
            $ext = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
            if (in_array($ext, ['jpg', 'jpeg'], true)) {
                $paths[] = $path;
            }
        }
        sort($paths, SORT_STRING);

        return array_values($paths);
    }

    private function importOneImage(
        string $recordId,
        string $sourcePath,
        string $imagesRoot,
        string $iiifPublicBase,
        IiifImageService $iiifService,
        string $ingestRunId,
        int $ord
    ): void {
        $webResourceId = (string) Str::uuid();
        $checksum = $iiifService->calculateSha256($sourcePath);

        $existing = DB::table(self::MASTER_SCHEMA.'.web_resources')
            ->where('record_id', $recordId)
            ->where('checksum_sha256', $checksum)
            ->first();
        if ($existing !== null) {
            $this->deleteSourceIfUnderIngestion($sourcePath);

            return;
        }

        $iiifIdentifier = $this->copyImageToImagesRootUuid($sourcePath, $imagesRoot, $webResourceId);
        $baseUrl = $iiifPublicBase.'/'.$iiifIdentifier;
        $mimeType = $iiifService->getMimeType($sourcePath);
        $dimensions = $iiifService->getImageDimensions($sourcePath);
        $iiifUrl = $iiifService->buildIiifUrl($baseUrl);

        DB::table(self::MASTER_SCHEMA.'.web_resources')->insert([
            'id' => $webResourceId,
            'record_id' => $recordId,
            'role' => 'iiif_image_api',
            'url' => $iiifUrl,
            'mime_type' => $mimeType,
            'checksum_sha256' => $checksum,
            'width' => $dimensions['width'],
            'height' => $dimensions['height'],
            'iiif_image_api_url' => $baseUrl,
            'ord' => $ord,
            'ext_json' => json_encode([
                'source' => [
                    'standard' => 'INTERVIEW_IMPORT',
                    'ingest_run_id' => $ingestRunId,
                    'filename' => basename($sourcePath),
                ],
            ], JSON_UNESCAPED_UNICODE),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->deleteSourceIfUnderIngestion($sourcePath);
    }

    private function copyImageToImagesRootUuid(string $sourcePath, string $imagesRoot, string $targetBasename): string
    {
        $ext = strtolower((string) pathinfo($sourcePath, PATHINFO_EXTENSION));
        if ($ext === '') {
            $ext = 'jpg';
        }
        $destFileName = $targetBasename.'.'.$ext;
        $destPath = $imagesRoot.DIRECTORY_SEPARATOR.$destFileName;
        if (@copy($sourcePath, $destPath) === false) {
            throw new RuntimeException("Copia immagine fallita verso IMAGES_ROOT: {$destPath}");
        }

        return $destFileName;
    }

    private function deleteSourceIfUnderIngestion(string $imagePath): void
    {
        $ingestionRoot = rtrim((string) config('ingestion.fs_root'), DIRECTORY_SEPARATOR);
        if ($ingestionRoot === '') {
            return;
        }
        $realRoot = realpath($ingestionRoot);
        $realPath = $imagePath !== '' ? realpath($imagePath) : false;
        if ($realRoot === false || $realPath === false || ! is_file($imagePath)) {
            return;
        }
        $prefix = $realRoot.DIRECTORY_SEPARATOR;
        if (! str_starts_with($realPath, $prefix) && $realPath !== $realRoot) {
            return;
        }
        @unlink($imagePath);
    }
}
