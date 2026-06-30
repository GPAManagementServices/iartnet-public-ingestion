<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Narration;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * API per la tabella iartnet_master.narrations.
 * Endpoint: narrationsList, narrationData.
 */
class NarrationsController extends Controller
{
    /**
     * Ritorna la lista di tutti i record dalla tabella narrations.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function narrationsList(): JsonResponse
    {
        try {
            $records = Narration::query()->orderBy('created_at', 'desc')->get();

            return response()->json([
                'success' => true,
                'data' => $records,
            ]);
        } catch (\Exception $e) {
            Log::error('NarrationsController: Error in narrationsList', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'NARRATIONS_LIST_ERROR',
                    'message' => $e->getMessage(),
                ],
            ], 500);
        }
    }

    /**
     * Ritorna il record di una narration per id (query param: id).
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function narrationData(Request $request): JsonResponse
    {
        try {
            $id = $request->query('id');

            if (empty($id)) {
                return response()->json([
                    'success' => false,
                    'error' => [
                        'code' => 'MISSING_ID',
                        'message' => 'Il parametro id è obbligatorio.',
                    ],
                ], 400);
            }

            $record = Narration::query()->find($id);

            if ($record === null) {
                return response()->json([
                    'success' => false,
                    'error' => [
                        'code' => 'NARRATION_NOT_FOUND',
                        'message' => "Narration con ID '{$id}' non trovata.",
                    ],
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => $record,
            ]);
        } catch (\Exception $e) {
            Log::error('NarrationsController: Error in narrationData', [
                'id' => $request->query('id'),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'NARRATION_DATA_ERROR',
                    'message' => $e->getMessage(),
                ],
            ], 500);
        }
    }
}
