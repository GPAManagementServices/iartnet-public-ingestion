<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Iiif\IiifManifestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Serves IIIF Presentation 3.0 manifests for master records.
 *
 * GET /api/iiif/manifest/{record_id}
 * Returns a valid IIIF 3.0 manifest or 404 if no image resources.
 * Manifests are cached for 1 hour to reduce database and generation load.
 */
class IiifManifestController extends Controller
{
    /** Cache TTL in seconds (1 hour). */
    private const CACHE_TTL = 3600;

    public function __construct(
        private readonly IiifManifestService $manifestService
    ) {
    }

    /**
     * Return IIIF Presentation 3.0 manifest for the given record_id.
     *
     * Uses cache key iiif_manifest_{record_id}. Cache is populated only when
     * the manifest exists (no caching of 404).
     */
    public function show(Request $request, string $record_id): JsonResponse
    {
        $manifestUrl = $request->url();
        $cacheKey = 'iiif_manifest_' . $record_id;

        $manifest = Cache::remember($cacheKey, self::CACHE_TTL, function () use ($record_id, $manifestUrl) {
            return $this->manifestService->buildManifest($record_id, $manifestUrl);
        });

        if ($manifest === null) {
            return response()->json([
                'error' => 'No image resources found for this record.',
            ], 404)->header('Content-Type', 'application/json');
        }

        return response()
            ->json($manifest)
            ->header('Content-Type', 'application/ld+json; profile="http://iiif.io/api/presentation/3/context.json"');
    }
}
