<?php

declare(strict_types=1);

namespace App\Services\Iiif;

use App\Models\WebResource;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Builds IIIF Presentation 3.0 manifests from iartnet_master.web_resources.
 *
 * Each image web_resource becomes one Canvas. No schema changes; read-only usage.
 * Output is compatible with Mirador and OpenSeadragon.
 *
 * Features:
 * - Image body service type/profile aligned to the Image API version in service id
 *   (/iiif/2/ → ImageService2 + Image API 2 level2 profile URI; /iiif/3/ or unknown → ImageService3 + level2)
 * - Manifest thumbnail from first image (/full/200,/0/default.jpg)
 * - Canvas thumbnails per canvas
 * - Metadata from ext_json (IIIF label/value or flat key-value)
 * - Manifest rights from web_resources.rights_uri when present
 * - Static provider (Iartnet Archive)
 * - Labels: manifest "default label", canvas "Page 1", "Page 2"
 *
 * @see https://iiif.io/api/presentation/3.0/
 * @see https://iiif.io/api/image/3.0/
 *
 * Example improved manifest (GET /api/iiif/manifest/{record_id}):
 * {
 *   "@context": "http://iiif.io/api/presentation/3/context.json",
 *   "id": "https://example.com/api/iiif/manifest/550e8400-e29b-41d4-a716-446655440000",
 *   "type": "Manifest",
 *   "label": { "none": ["default label"] },
 *   "metadata": [
 *     { "label": { "it": ["Author"] }, "value": { "it": ["Mario Rossi"] } }
 *   ],
 *   "rights": "https://rights.example.org/cc-by",
 *   "provider": [{
 *     "id": "https://iartnet.org",
 *     "type": "Agent",
 *     "label": { "it": ["Iartnet Archive"] }
 *   }],
 *   "thumbnail": [{
 *     "id": "https://iiif.example.com/iiif/3/abc/full/200,/0/default.jpg",
 *     "type": "Image",
 *     "format": "image/jpeg"
 *   }],
 *   "items": [
 *     {
 *       "id": "https://example.com/.../canvas/0",
 *       "type": "Canvas",
 *       "width": 800,
 *       "height": 1200,
 *       "label": { "none": ["Page 1"] },
 *       "thumbnail": [{ "id": ".../full/200,/0/default.jpg", "type": "Image", "format": "image/jpeg" }],
 *       "items": [{
 *         "id": "https://example.com/.../canvas/0/page/0",
 *         "type": "AnnotationPage",
 *         "items": [{
 *           "id": "https://example.com/.../canvas/0/page/0/0",
 *           "type": "Annotation",
 *           "motivation": "painting",
 *           "body": {
 *             "id": "https://iiif.example.com/iiif/3/abc/full/max/0/default.jpg",
 *             "type": "Image",
 *             "format": "image/jpeg",
 *             "width": 800,
 *             "height": 1200,
 *             "service": [{ "id": "https://iiif.example.com/iiif/3/abc", "type": "ImageService3", "profile": "level2" }]
 *           },
 *           "target": "https://example.com/.../canvas/0"
 *         }]
 *       }]
 *     }
 *   ]
 * }
 */
class IiifManifestService
{
    private const CONTEXT = 'http://iiif.io/api/presentation/3/context.json';

    /** Image request path for full-size image (body id). */
    private const IMAGE_REQUEST = '/full/max/0/default.jpg';

    /** Thumbnail request path (200px on longest side). */
    private const THUMBNAIL_REQUEST = '/full/200,/0/default.jpg';

    /** IIIF Image API 3.0 level2 profile. */
    private const IMAGE_SERVICE_PROFILE = 'level2';

    /** Static provider block for the manifest. */
    private const PROVIDER = [
        'id' => 'https://iartnet.org',
        'type' => 'Agent',
        'label' => [
            'it' => ['Iartnet Archive'],
        ],
    ];

    /**
     * Build a IIIF Presentation 3.0 manifest for the given record_id.
     *
     * record_id può essere UUID (records.id) o stable_id (es. OA_4t010-00004); viene sempre risolto a UUID per le query.
     *
     * Structure: Manifest (label, metadata, rights, provider, thumbnail, items)
     *   -> items: Canvas[] (id, type, width, height, label, thumbnail, items)
     *     -> items: AnnotationPage (items: Annotation[])
     *       -> Annotation (motivation: painting, body: Image with service, target: Canvas)
     *
     * @param  string  $recordId  UUID del record oppure stable_id
     * @param  string  $manifestUrl  Full URL of this manifest (for id and canvas URIs)
     * @return array<string, mixed>|null  Manifest structure or null if no image resources
     */
    public function buildManifest(string $recordId, string $manifestUrl): ?array
    {
        $recordUuid = $this->resolveRecordIdToUuid(trim($recordId));
        if ($recordUuid === null) {
            return null;
        }

        $resources = $this->getImageResourcesForRecord($recordUuid);

        if ($resources->isEmpty()) {
            return null;
        }

        $baseUrl = rtrim($manifestUrl, '/');
        $firstResource = $resources->first();
        $canvases = [];
        foreach ($resources->values() as $index => $resource) {
            $canvases[] = $this->buildCanvas($resource, $baseUrl, $index);
        }

        // Manifest-level thumbnail: first image at 200px
        $thumbnail = $this->buildThumbnailObject($firstResource);

        // Manifest-level rights: from first resource if present
        $rights = $this->getManifestRights($resources);

        // Metadata: Autore, Anno, Tecnica, Luogo da card_data (record_id → stable_id → v_record_full_json_en) + ext_json
        $metadata = $this->loadRecordMetadataFromCardData($recordUuid);
        $metadata = array_merge(
            $metadata,
            $this->buildMetadataFromExtJson($firstResource->ext_json ?? null)
        );

        $manifest = [
            '@context' => self::CONTEXT,
            'id' => $manifestUrl,
            'type' => 'Manifest',
            'label' => [
                'none' => ['default label'],
            ],
            'metadata' => $metadata,
            'provider' => [self::PROVIDER],
            'thumbnail' => $thumbnail,
            'items' => $canvases,
        ];
        if ($rights !== null) {
            $manifest['rights'] = $rights;
        }

        return $manifest;
    }

    /**
     * Fetch image web_resources for record, ordered by ord.
     *
     * @return Collection<int, WebResource>
     */
    protected function getImageResourcesForRecord(string $recordId): Collection
    {
        return WebResource::query()
            ->where('record_id', $recordId)
            ->where('mime_type', 'like', 'image/%')
            ->orderBy('ord')
            ->orderBy('id')
            ->get();
    }

    /**
     * Build one Canvas (IIIF 3.0) from a WebResource.
     *
     * Canvas includes: id, type, width, height, label (Page N), thumbnail, items (AnnotationPage).
     */
    protected function buildCanvas(WebResource $resource, string $baseUrl, int $index): array
    {
        $canvasId = $baseUrl . '/canvas/' . $index;
        $width = max(1, (int) $resource->width);
        $height = max(1, (int) $resource->height);
        $pageNumber = $index + 1;

        $imageUrl = $this->getImageUrl($resource);
        $imageApiUrl = $this->getImageApiBaseUrl($resource);

        // Body: Image with IIIF Image API service (required by Mirador/OpenSeadragon)
        $body = [
            'id' => $imageUrl,
            'type' => 'Image',
            'format' => $resource->mime_type ?? 'image/jpeg',
            'width' => $width,
            'height' => $height,
            'service' => [
                [
                    'id' => $imageApiUrl,
                    'type' => $this->getImageServiceType($imageApiUrl),
                    'profile' => $this->getImageServiceProfile($imageApiUrl),
                ],
            ],
        ];
        if (! empty($resource->rights_uri)) {
            $body['rights'] = $resource->rights_uri;
        }

        $annotationPageId = $canvasId . '/page/0';
        $annotationId = $annotationPageId . '/0';

        // Canvas thumbnail: same rule as manifest thumbnail
        $canvasThumbnail = $this->buildThumbnailObject($resource);

        return [
            'id' => $canvasId,
            'type' => 'Canvas',
            'width' => $width,
            'height' => $height,
            'label' => [
                'none' => ['Page ' . $pageNumber],
            ],
            'thumbnail' => $canvasThumbnail,
            'items' => [
                [
                    'id' => $annotationPageId,
                    'type' => 'AnnotationPage',
                    'items' => [
                        [
                            'id' => $annotationId,
                            'type' => 'Annotation',
                            'motivation' => 'painting',
                            'body' => $body,
                            'target' => $canvasId,
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * Full-size image URL for the painting body: {iiif_image_api_url}/full/max/0/default.jpg
     */
    protected function getImageUrl(WebResource $resource): string
    {
        $base = $this->getImageApiBaseUrl($resource);

        return $base . self::IMAGE_REQUEST;
    }

    /**
     * Base URL of the IIIF Image API (no trailing slash).
     */
    protected function getImageApiBaseUrl(WebResource $resource): string
    {
        $base = $resource->iiif_image_api_url ?? $resource->url ?? '';

        return rtrim($base, '/');
    }

    /**
     * IIIF Presentation 3 service type: must match the Image API version implied by service id.
     */
    private function getImageServiceType(string $imageApiUrl): string
    {
        if (str_contains($imageApiUrl, '/iiif/2/')) {
            return 'ImageService2';
        }
        if (str_contains($imageApiUrl, '/iiif/3/')) {
            return 'ImageService3';
        }

        return 'ImageService3';
    }

    /**
     * Profile string for the service block (Image API 2 uses URI; Image API 3 uses short token).
     */
    private function getImageServiceProfile(string $imageApiUrl): string
    {
        if (str_contains($imageApiUrl, '/iiif/2/')) {
            return 'http://iiif.io/api/image/2/level2.json';
        }

        return self::IMAGE_SERVICE_PROFILE;
    }

    /**
     * Build IIIF thumbnail structure for a resource.
     *
     * "thumbnail": [{ "id": ".../full/200,/0/default.jpg", "type": "Image", "format": "image/jpeg" }]
     *
     * @return array<int, array<string, string>>
     */
    protected function buildThumbnailObject(WebResource $resource): array
    {
        $base = $this->getImageApiBaseUrl($resource);
        $thumbUrl = $base . self::THUMBNAIL_REQUEST;
        $format = $resource->mime_type ?? 'image/jpeg';

        return [
            [
                'id' => $thumbUrl,
                'type' => 'Image',
                'format' => $format,
            ],
        ];
    }

    /**
     * Manifest-level rights: use first resource's rights_uri if any.
     *
     * @param  Collection<int, WebResource>  $resources
     * @return string|null  rights URI or null
     */
    protected function getManifestRights(Collection $resources): ?string
    {
        foreach ($resources as $resource) {
            if (! empty($resource->rights_uri)) {
                return $resource->rights_uri;
            }
        }

        return null;
    }

    /**
     * Carica metadati scheda (Autore, Anno, Tecnica, Luogo) per il manifest.
     * record_id può essere UUID (records.id) o stable_id: si risolve stable_id e si legge record_json da v_record_full_json_en.
     *
     * @param  string  $recordId  UUID del record oppure stable_id (es. OA_4t010-00004)
     * @return array<int, array{label: array<string, array<string>>, value: array<string, array<string>>}>
     */
    protected function loadRecordMetadataFromCardData(string $recordId): array
    {
        $schema = 'iartnet_master';
        $recordId = trim($recordId);
        $stableId = null;

        if ($this->isUuid($recordId)) {
            $row = DB::selectOne(
                "SELECT stable_id FROM \"{$schema}\".records WHERE id = ?::uuid LIMIT 1",
                [$recordId]
            );
            $stableId = $row->stable_id ?? null;
        } else {
            $stableId = $recordId;
        }

        if ($stableId === null || $stableId === '') {
            return [];
        }

        $cardRow = DB::selectOne(
            "SELECT record_json FROM \"{$schema}\".v_record_full_json_en WHERE stable_id = ? LIMIT 1",
            [$stableId]
        );
        if ($cardRow === null || empty($cardRow->record_json ?? null)) {
            return [];
        }

        $recordJson = $cardRow->record_json;
        if (is_string($recordJson)) {
            $recordJson = json_decode($recordJson, true);
        }
        if (! is_array($recordJson)) {
            return [];
        }

        return $this->buildMetadataFromRecordJson($recordJson);
    }

    /**
     * Risolve l'identificatore (UUID o stable_id) nell'UUID del record per le query su web_resources.
     *
     * @return string|null  UUID del record o null se non trovato
     */
    protected function resolveRecordIdToUuid(string $recordId): ?string
    {
        $recordId = trim($recordId);
        if ($recordId === '') {
            return null;
        }
        if ($this->isUuid($recordId)) {
            return $recordId;
        }
        $schema = 'iartnet_master';
        $row = DB::selectOne(
            "SELECT id FROM \"{$schema}\".records WHERE stable_id = ? LIMIT 1",
            [$recordId]
        );

        return $row !== null ? (string) $row->id : null;
    }

    /**
     * Verifica se la stringa è un UUID valido (8-4-4-4-12 hex).
     */
    protected function isUuid(string $value): bool
    {
        return (bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value);
    }

    /**
     * Estrae da record_json (record_fields) i campi Autore, Anno, Tecnica, Luogo in formato IIIF metadata.
     * Chiavi come nel card_data: AU/AUT, AU (autore), DT/DTS, DT (anno), MT, medium (tecnica), LC/PVC, LC (luogo).
     *
     * @param  array<string, mixed>  $recordJson
     * @return array<int, array{label: array<string, array<string>>, value: array<string, array<string>>}>
     */
    protected function buildMetadataFromRecordJson(array $recordJson): array
    {
        $fieldsMap = $this->extractRecordFieldsMap($recordJson);
        if ($fieldsMap === []) {
            return [];
        }

        // Chiavi effettive in record_fields (lowercase): da API card_data / v_record_full_json_en
        $wanted = [
            'Autore' => ['au/aut', 'au', 'aut', 'autore', 'dc_creator'],
            'Anno' => ['dt/dts', 'dt', 'dta', 'anno', 'data', 'anno_di_creazione'],
            'Tecnica' => ['mt', 'medium', 'tec', 'tecnica'],
            'Luogo' => ['lc/pvc', 'lc', 'luo', 'luogo', 'dc_spatial'],
        ];

        $out = [];
        foreach ($wanted as $labelIt => $possibleKeys) {
            $value = null;
            foreach ($possibleKeys as $key) {
                if (isset($fieldsMap[$key])) {
                    $val = trim((string) $fieldsMap[$key]);
                    if ($val !== '') {
                        $value = $val;
                        break;
                    }
                }
            }
            if ($value !== null) {
                $out[] = [
                    'label' => ['it' => [$labelIt]],
                    'value' => ['it' => [$value]],
                ];
            }
        }

        return $out;
    }

    /**
     * Estrae da record_json.record_fields una mappa chiave (lowercase) => valore testuale.
     *
     * @param  array<string, mixed>  $recordJson
     * @return array<string, string>
     */
    protected function extractRecordFieldsMap(array $recordJson): array
    {
        $recordFields = $recordJson['record_fields'] ?? null;
        if (! is_array($recordFields)) {
            return [];
        }

        $map = [];
        foreach ($recordFields as $fieldName => $items) {
            $key = mb_strtolower(trim((string) $fieldName));
            if ($key === '') {
                continue;
            }
            $value = '';
            if (is_array($items)) {
                $parts = [];
                foreach ($items as $item) {
                    if (is_array($item) && isset($item['value'])) {
                        $parts[] = (string) $item['value'];
                    }
                }
                $value = implode(' | ', $parts);
            } else {
                $value = (string) $items;
            }
            $map[$key] = trim($value);
        }

        return $map;
    }

    /**
     * Build IIIF metadata array from web_resources.ext_json.
     *
     * If ext_json contains a "metadata" key with array of { label, value }, use it.
     * Otherwise if ext_json is a flat key-value map, convert to IIIF format.
     * If no metadata exists, return empty array.
     *
     * @param  array<string, mixed>|null  $extJson
     * @return array<int, array{label: array<string, array<string>>, value: array<string, array<string>>}>
     */
    protected function buildMetadataFromExtJson(?array $extJson): array
    {
        if ($extJson === null || $extJson === []) {
            return [];
        }

        // Already IIIF-style metadata array
        if (isset($extJson['metadata']) && is_array($extJson['metadata'])) {
            $out = [];
            foreach ($extJson['metadata'] as $entry) {
                if (isset($entry['label'], $entry['value']) && is_array($entry['label']) && is_array($entry['value'])) {
                    $out[] = [
                        'label' => $entry['label'],
                        'value' => $entry['value'],
                    ];
                }
            }

            return $out;
        }

        // Flat key-value: convert to IIIF metadata (label/value with "it" language)
        $out = [];
        foreach ($extJson as $key => $value) {
            if (is_array($value) || is_object($value)) {
                continue;
            }
            $out[] = [
                'label' => ['it' => [(string) $key]],
                'value' => ['it' => [(string) $value]],
            ];
        }

        return $out;
    }
}
