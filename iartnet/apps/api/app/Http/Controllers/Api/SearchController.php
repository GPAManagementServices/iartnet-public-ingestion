<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * API ricerca pubblica e suggerimenti termini (FTS + trigram + fuzzy su iartnet_master).
 * Chiama le funzioni PostgreSQL search_public e search_suggest_terms.
 */
class SearchController extends Controller
{
    private const SCHEMA = 'iartnet_master';

    /**
     * Ricerca pubblica "google-like" (solo published, MIXED IT/EN).
     *
     * GET /api/search_public?q=...&limit=...&offset=...&mode=AND|OR
     */
    public function searchPublic(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q' => 'required|string|min:3|max:500',
            'limit' => 'sometimes|integer|min:1|max:50',
            'offset' => 'sometimes|integer|min:0|max:5000',
            'mode' => 'sometimes|string|in:AND,OR',
        ]);

        $q = trim($validated['q']);
        $limit = (int) ($validated['limit'] ?? 10);
        $offset = (int) ($validated['offset'] ?? 0);
        $mode = $validated['mode'] ?? 'AND';

        try {
            $rows = DB::select(
                'SELECT * FROM '.self::SCHEMA.'.search_public(?, ?, ?, ?)',
                [$q, $limit, $offset, $mode]
            );

            $results = array_map(static function ($row): array {
                
                $strTitolo = $row->title_en ?? $row->title_it ?? "";
              
                $strTitolo = str_replace('|', ',', $strTitolo);
                $strTitolo = str_replace('/', ',', $strTitolo);
        
                // filtra caratteri (aggiunto il punto)
                $strTitolo = preg_replace("/[^\p{Latin},.\-\/'’]/u", ' ', $strTitolo);

                //Rimuove i caratteri di punteggiatura all'inizio della stringa
                $strTitolo = preg_replace('/^[\s\.,;:]+/u', '', $strTitolo);                
        
                // pulizia spazi multipli
                $strTitolo = preg_replace('/\s+/', ' ', $strTitolo);                

                $A = "titolo attribuito";
                $B = "titolo proprio";
                $C = "titoli attribuiti";
                $D = "titoli proprii";                   
       
                $strTitolo = str_replace($A, '', $strTitolo);
                $strTitolo = str_replace($B, '', $strTitolo);    
                $strTitolo = str_replace($C, '', $strTitolo);
                $strTitolo = str_replace($D, '', $strTitolo);                            

                if ( mb_strlen ($strTitolo) > 100) {                
                 $strTitolo = substr($strTitolo, 0, 100);
                 $strTitolo = $strTitolo . "...";
                }

                return [
                    'stable_id' => $row->stable_id ?? null,
                    'used_lang' => $row->used_lang ?? null,
                    'score_total' => $row->score_total !== null ? (float) $row->score_total : null,
                    'snippet' => $row->snippet ?? null,
                    'record_id' => $row->record_id ?? null,
                    'title_en' => $strTitolo,
                    'score_fts' => $row->score_fts !== null ? (float) $row->score_fts : null,
                    'score_fuzzy' => $row->score_fuzzy !== null ? (float) $row->score_fuzzy : null,
                ];
            }, $rows);

            return response()->json([
                'q' => $q,
                'mode' => $mode,
                'limit' => $limit,
                'offset' => $offset,
                'count' => count($results),
                'results' => $results,
            ]);
           
        } catch (\Throwable $e) {
            Log::error('SearchController::searchPublic', [
                'q' => $q,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'error' => 'search_failed',
                'message' => 'Ricerca non disponibile.',
            ], 500);
        }
    }

    /**
     * Suggerimenti termini per autocomplete.
     *
     * GET /api/search_suggest_terms?q=...&limit=...&langMode=MIXED|EN|IT
     */
    public function searchSuggestTerms(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q' => 'required|string|min:2|max:200',
            'limit' => 'sometimes|integer|min:1|max:20',
            'langMode' => 'sometimes|string|in:MIXED,EN,IT',
        ]);

        $q = trim($validated['q']);
        $limit = (int) ($validated['limit'] ?? 8);
        $langMode = $validated['langMode'] ?? 'MIXED';

        try {
            $rows = DB::select(
                'SELECT * FROM '.self::SCHEMA.'.search_suggest_terms(?, ?, ?)',
                [$q, $limit, $langMode]
            );

            $suggestions = array_map(static function ($row): array {
                return [
                    'term' => $row->term ?? null,
                    'lang' => $row->lang ?? null,
                    'freq' => (int) ($row->freq ?? 0),
                    'score' => $row->score !== null ? (float) $row->score : null,
                ];
            }, $rows);

            return response()->json([
                'q' => $q,
                'langMode' => $langMode,
                'limit' => $limit,
                'suggestions' => $suggestions,
            ]);
        } catch (\Throwable $e) {
            Log::error('SearchController::searchSuggestTerms', [
                'q' => $q,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'error' => 'suggest_failed',
                'message' => 'Suggerimenti non disponibili.',
            ], 500);
        }
    }
}
