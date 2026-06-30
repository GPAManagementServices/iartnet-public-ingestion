<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MasterDcRecord;
use App\Services\MasterData\CardDetailRecordRowsClassifier;
use App\Services\MasterData\CardTitleItResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MasterDataCardController extends Controller
{
    public function __construct(
        private readonly CardTitleItResolver $cardTitleItResolver,
        private readonly CardDetailRecordRowsClassifier $cardDetailRecordRowsClassifier,
    ) {}

    /**
     * Ritorna la lista delle schede dalla view vc_dc_rec_table (record + institution + record_kv).
     */
    public function cardsList(): JsonResponse
    {
        try {
            // Model MasterDcRecord usa la view iartnet_master.vc_dc_rec_table
            $records = MasterDcRecord::all();

            // Ritorna l'array dei records come JSON
            return response()->json([
                'success' => true,
                'data' => $records,
            ]);
        } catch (\Exception $e) {
            // Log dell'errore per debug
            Log::error('MasterDataCardController: Error in cardsList', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Ritorna JSON con codice di errore e messaggio
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'CARDS_LIST_ERROR',
                    'message' => $e->getMessage(),
                ],
            ], 500);
        }
    }

    /**
     * Ritorna i dettagli di una scheda dalla view v_record_full.
     */
    public function cardData(Request $request): JsonResponse
    {
        Log::info('MasterDataCardController: cardData method called', [
            'url' => $request->fullUrl(),
            'method' => $request->method(),
            'query_params' => $request->query(),
        ]);

        try {
            // Ottiene card_id dal query parameter
            $cardId = $request->query('card_id');

            Log::debug('MasterDataCardController: CardData', [
                'card_id' => $cardId,
            ]);

            // Valida che card_id sia presente
            if (empty($cardId)) {
                return response()->json([
                    'success' => false,
                    'error' => [
                        'code' => 'MISSING_CARD_ID',
                        'message' => 'Il parametro card_id è obbligatorio.',
                    ],
                ], 400);
            }

            // Esegue la query sulla view v_record_full
            $record = DB::selectOne(
                'SELECT * FROM iartnet_master.v_record_full_json_en WHERE stable_id = ?',
                [$cardId]
            );

            // Se il record non esiste, ritorna errore 404
            if ($record === null) {
                return response()->json([
                    'success' => false,
                    'error' => [
                        'code' => 'CARD_NOT_FOUND',
                        'message' => "Scheda con ID '{$cardId}' non trovata.",
                    ],
                ], 404);
            }

            // record_json: record_fields.title_it calcolato in PHP (view invariata)
            $recordJsonArray = $this->decodeRecordJsonForApi($record->record_json);

            $selCardType = null;
            if (isset($record->card_type) && is_string($record->card_type) && $record->card_type !== '') {
                $selCardType = $record->card_type;
            } else {
                $selCardType = $this->cardDetailRecordRowsClassifier->extractCardTypeFromRecordJson($recordJsonArray);
            }

            $idScheda = (string) $record->record_id;
            $valueTitleIt = $this->getCardTitleIt($idScheda, $selCardType);

            if (! isset($recordJsonArray['record_fields']) || ! is_array($recordJsonArray['record_fields'])) {
                $recordJsonArray['record_fields'] = [];
            }
            $recordJsonArray['record_fields']['title_it'] = [
                [
                    'lang' => 'en',
                    'value' => $valueTitleIt,
                    'origin' => 'manual',
                ],
            ];

            $imagesMapField = $this->getInterviewImagesMap($idScheda, $selCardType);
            if ($imagesMapField !== null) {
                $recordJsonArray['record_fields']['images_map'] = $imagesMapField;
            }

            $payload = json_decode(json_encode($record), true);
            if (! is_array($payload)) {
                $payload = [];
            }
            // Come con response()->json($record): record_json dal DB è tipicamente una stringa JSON;
            // manteniamo lo stesso tipo in risposta (non un oggetto annidato).
            $encodedRecordJson = json_encode($recordJsonArray, JSON_UNESCAPED_UNICODE);
            $payload['record_json'] = $encodedRecordJson !== false ? $encodedRecordJson : '{}';

            return response()->json($payload);
        } catch (\Exception $e) {
            // Log dell'errore per debug
            Log::error('MasterDataCardController: Error in cardData', [
                'card_id' => $request->query('card_id'),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Ritorna JSON con codice di errore e messaggio
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'CARD_DATA_ERROR',
                    'message' => $e->getMessage(),
                ],
            ], 500);
        }
    }

    /**
     * Decodifica record_json dalla view in array associativo (mutabile per title_it).
     *
     * @return array<string, mixed>
     */
    private function decodeRecordJsonForApi(mixed $recordJson): array
    {
        if (is_array($recordJson)) {
            return $recordJson;
        }
        if (is_string($recordJson)) {
            $decoded = json_decode($recordJson, true);

            return is_array($decoded) ? $decoded : [];
        }
        if (is_object($recordJson)) {
            $encoded = json_encode($recordJson);
            if ($encoded === false) {
                return [];
            }
            $decoded = json_decode($encoded, true);

            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }

    /**
     * Calcola il titolo IT da record_kv in base a id scheda e card_type (specifica API).
     */
    private function getCardTitleIt(string $idScheda, ?string $selCardType): string
    {
        return $this->cardTitleItResolver->getCardTitleIt($idScheda, $selCardType);
    }

    /**
     * Per card_type Interview: legge ext_json da iartnet_master.interviews e lo espone come record_fields.images_map.
     *
     * @return array<int, array{lang: string, value: mixed, origin: string}>|null
     */
    private function getInterviewImagesMap(string $idScheda, ?string $selCardType): ?array
    {
        $type = $selCardType === null ? '' : strtoupper(trim($selCardType));
        if ($type !== 'INTERVISTA') {
            return null;
        }

        $row = DB::selectOne(
            'SELECT ext_json FROM iartnet_master.interviews WHERE record_id = ?',
            [$idScheda]
        );

        if ($row === null) {
            return null;
        }

        $extJson = $row->ext_json;
        if (is_string($extJson)) {
            $decoded = json_decode($extJson, true);
            $extJson = is_array($decoded) ? $decoded : [];
        } elseif (is_object($extJson)) {
            $encoded = json_encode($extJson);
            $decoded = $encoded !== false ? json_decode($encoded, true) : null;
            $extJson = is_array($decoded) ? $decoded : [];
        } elseif (! is_array($extJson)) {
            $extJson = [];
        }

        return [
            [
                'lang' => 'en',
                'value' => $extJson,
                'origin' => 'manual',
            ],
        ];
    }
}
