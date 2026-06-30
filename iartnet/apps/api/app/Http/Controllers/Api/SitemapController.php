<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Elenco leggero di record master pubblicati per sitemap frontend (paginato).
 */
class SitemapController extends Controller
{
    private const SCHEMA = 'iartnet_master';

    private const DEFAULT_LIMIT = 5000;

    private const MAX_LIMIT = 5000;

    /**
     * GET /api/sitemap/records?offset=0&limit=5000
     *
     * Solo publish_state = published (allineato a search_public).
     */
    public function records(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'offset' => 'sometimes|integer|min:0|max:10000000',
            'limit' => 'sometimes|integer|min:1|max:'.self::MAX_LIMIT,
        ]);

        $offset = (int) ($validated['offset'] ?? 0);
        $limit = (int) ($validated['limit'] ?? self::DEFAULT_LIMIT);

        try {
            $base = DB::table(self::SCHEMA.'.records')
                ->where('publish_state', 'published');

            $total = (clone $base)->count();

            $rows = (clone $base)
                ->orderBy('stable_id')
                ->offset($offset)
                ->limit($limit)
                ->get(['stable_id']);

            $stableIds = $rows
                ->map(static fn ($row): string => trim((string) ($row->stable_id ?? '')))
                ->filter(static fn (string $id): bool => $id !== '')
                ->values()
                ->all();
            $nextOffset = $offset + count($stableIds);

            return response()->json([
                'success' => true,
                'data' => $stableIds,
                'meta' => [
                    'offset' => $offset,
                    'limit' => $limit,
                    'total' => $total,
                    'count' => count($stableIds),
                    'has_more' => $nextOffset < $total,
                    'next_offset' => $nextOffset < $total ? $nextOffset : null,
                ],
            ]);
        } catch (\Throwable $e) {
            Log::error('SitemapController::records', [
                'offset' => $offset,
                'limit' => $limit,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'SITEMAP_RECORDS_ERROR',
                    'message' => $e->getMessage(),
                ],
            ], 500);
        }
    }
}
