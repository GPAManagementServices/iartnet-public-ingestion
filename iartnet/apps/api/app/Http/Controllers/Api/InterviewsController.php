<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Interview;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * API per la tabella iartnet_master.interviews.
 * Endpoint: interviewsList, interviewData.
 */
class InterviewsController extends Controller
{
    /**
     * Ritorna la lista di tutti i record dalla tabella interviews.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function interviewsList(): JsonResponse
    {
        try {
            $records = Interview::query()->orderBy('created_at', 'desc')->get();

            return response()->json([
                'success' => true,
                'data' => $records,
            ]);
        } catch (\Exception $e) {
            Log::error('InterviewsController: Error in interviewsList', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'INTERVIEWS_LIST_ERROR',
                    'message' => $e->getMessage(),
                ],
            ], 500);
        }
    }

    /**
     * Ritorna il record di una interview per id (query param: id).
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function interviewData(Request $request): JsonResponse
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

            $record = Interview::query()->find($id);

            if ($record === null) {
                return response()->json([
                    'success' => false,
                    'error' => [
                        'code' => 'INTERVIEW_NOT_FOUND',
                        'message' => "Interview con ID '{$id}' non trovata.",
                    ],
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => $record,
            ]);
        } catch (\Exception $e) {
            Log::error('InterviewsController: Error in interviewData', [
                'id' => $request->query('id'),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'INTERVIEW_DATA_ERROR',
                    'message' => $e->getMessage(),
                ],
            ], 500);
        }
    }
}
