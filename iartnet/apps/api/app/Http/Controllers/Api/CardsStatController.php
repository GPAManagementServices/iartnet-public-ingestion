<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\MasterData\CardsStatService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class CardsStatController extends Controller
{
    public function __construct(
        private readonly CardsStatService $cardsStatService,
    ) {}

    /**
     * Ritorna i totali delle schede importate per institution.
     *
     * GET /api/cards_stat
     */
    public function cardsStat(): JsonResponse
    {
        try {
            $stats = $this->cardsStatService->getCardsStat();

            return response()->json([
                'success' => true,
                'data' => $stats,
            ]);
        } catch (\Throwable $e) {
            Log::error('CardsStatController::cardsStat', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'CARDS_STAT_ERROR',
                    'message' => $e->getMessage(),
                ],
            ], 500);
        }
    }
}

