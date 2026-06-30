@php
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

$records = [];
$kvData = [];
$assets = [];

if ($targetSchema && $runId) {
    try {
        // Get all records for this import run
        $records = DB::select("
            SELECT record_id, title, nctr, nctn, normativa_code, normativa_version, valid_xsd, error_count
            FROM \"{$targetSchema}\".record
            WHERE import_run_id = ?
            ORDER BY scheda_idx
        ", [$runId]);

        // If a record is selected, get its KV pairs and assets
        if ($selectedRecordId) {
            $kvData = DB::select("
                SELECT id, xpath, occurrence_idx, value_text
                FROM \"{$targetSchema}\".kv
                WHERE record_id = ?
                ORDER BY xpath, occurrence_idx
            ", [$selectedRecordId]);

            $assets = DB::select("
                SELECT id, filename, exists_flag, size_bytes
                FROM \"{$targetSchema}\".asset
                WHERE record_id = ?
                ORDER BY filename
            ", [$selectedRecordId]);
        }
    } catch (\Exception $e) {
        // Handle error silently
    }
}
@endphp

<div class="space-y-6" x-data="{ selectedRecordId: @js($selectedRecordId) }">
    <div>
        <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-4">Schede Importate</h3>
        <div class="overflow-x-auto">
            <table class="w-full border-collapse">
                <thead class="bg-gray-50 dark:bg-gray-800">
                    <tr>
                        <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider border-b border-gray-200 dark:border-gray-700">Record ID</th>
                        <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider border-b border-gray-200 dark:border-gray-700">Titolo</th>
                        <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider border-b border-gray-200 dark:border-gray-700">NCTR</th>
                        <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider border-b border-gray-200 dark:border-gray-700">NCTN</th>
                        <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider border-b border-gray-200 dark:border-gray-700">Normativa</th>
                        <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider border-b border-gray-200 dark:border-gray-700">Valida XSD</th>
                        <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider border-b border-gray-200 dark:border-gray-700">Errori</th>
                        <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider border-b border-gray-200 dark:border-gray-700">Azioni</th>
                    </tr>
                </thead>
                <tbody class="bg-white dark:bg-gray-800">
                    @forelse($records as $record)
                        <tr class="border-b border-gray-200 dark:border-gray-700 transition-colors hover:bg-gray-50 dark:hover:bg-gray-700">
                            <td class="px-4 py-3 text-sm text-gray-900 dark:text-gray-100 font-mono">{{ $record->record_id ?? '-' }}</td>
                            <td class="px-4 py-3 text-sm text-gray-900 dark:text-gray-100">{{ $record->title ?? '-' }}</td>
                            <td class="px-4 py-3 text-sm text-gray-700 dark:text-gray-300">{{ $record->nctr ?? '-' }}</td>
                            <td class="px-4 py-3 text-sm text-gray-700 dark:text-gray-300">{{ $record->nctn ?? '-' }}</td>
                            <td class="px-4 py-3 text-sm text-gray-700 dark:text-gray-300">{{ $record->normativa_code ?? '-' }} @if($record->normativa_version) v{{ $record->normativa_version }} @endif</td>
                            <td class="px-4 py-3 text-sm">
                                @if($record->valid_xsd)
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-success-100 text-success-800 dark:bg-success-900 dark:text-success-200">Sì</span>
                                @else
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-danger-100 text-danger-800 dark:bg-danger-900 dark:text-danger-200">No</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-sm text-gray-700 dark:text-gray-300">{{ $record->error_count ?? 0 }}</td>
                            <td class="px-4 py-3 text-sm">
                                <button 
                                    type="button"
                                    wire:click="$set('selectedRecordId', '{{ $record->record_id }}')"
                                    class="inline-flex items-center px-3 py-1.5 border border-transparent text-xs font-medium rounded-md text-white bg-primary-600 hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500">
                                    Visualizza
                                </button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="px-4 py-8 text-center text-sm text-gray-500 dark:text-gray-400">
                                Nessuna scheda importata
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    @if($selectedRecordId)
        <div class="border-t border-gray-200 dark:border-gray-700 pt-6">
            <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-4">Dati Scheda (Tabella KV)</h3>
            <div class="overflow-x-auto">
                <table class="w-full border-collapse">
                    <thead class="bg-gray-50 dark:bg-gray-800">
                        <tr>
                            <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider border-b border-gray-200 dark:border-gray-700">XPath</th>
                            <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider border-b border-gray-200 dark:border-gray-700">Occorrenza</th>
                            <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider border-b border-gray-200 dark:border-gray-700">Valore</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white dark:bg-gray-800">
                        @forelse($kvData as $kv)
                            <tr class="border-b border-gray-200 dark:border-gray-700 transition-colors hover:bg-gray-50 dark:hover:bg-gray-700">
                                <td class="px-4 py-3 text-sm font-mono text-gray-900 dark:text-gray-100">{{ $kv->xpath }}</td>
                                <td class="px-4 py-3 text-sm text-gray-700 dark:text-gray-300">{{ $kv->occurrence_idx }}</td>
                                <td class="px-4 py-3 text-sm text-gray-700 dark:text-gray-300">{{ Str::limit($kv->value_text, 100) }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="3" class="px-4 py-8 text-center text-sm text-gray-500 dark:text-gray-400">
                                    Nessun dato disponibile per questa scheda
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div class="border-t border-gray-200 dark:border-gray-700 pt-6">
            <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-4">Immagini Collegate</h3>
            @if(count($assets) > 0)
                <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4">
                    @foreach($assets as $asset)
                        @if($asset->exists_flag)
                            @php
                                // Images are stored under ingestion root: tmp/{runId}/immagini/
                                $tmpPath = \App\Support\IngestionPaths::tmpPath($runId);
                                $imagePath = $tmpPath . DIRECTORY_SEPARATOR . 'immagini' . DIRECTORY_SEPARATOR . $asset->filename;
                                // For now, we'll use a data URI or placeholder since tmp is not publicly accessible
                                // In production, you may want to create a route/controller to serve these images
                                $publicPath = '#';
                                if (file_exists($imagePath)) {
                                    $imageData = file_get_contents($imagePath);
                                    $imageInfo = getimagesize($imagePath);
                                    $mimeType = $imageInfo ? $imageInfo['mime'] : 'image/jpeg';
                                    $publicPath = 'data:' . $mimeType . ';base64,' . base64_encode($imageData);
                                }
                            @endphp
                            <div class="relative group">
                                <a href="{{ $publicPath }}" target="_blank" class="block">
                                    <img 
                                        src="{{ file_exists($imagePath) ? $publicPath : '#' }}" 
                                        alt="{{ $asset->filename }}"
                                        class="w-full h-48 object-cover rounded-lg border border-gray-200 dark:border-gray-700 hover:border-primary-500 transition-colors"
                                        onerror="this.src='data:image/svg+xml,%3Csvg xmlns=\'http://www.w3.org/2000/svg\' width=\'200\' height=\'200\'%3E%3Crect fill=\'%23ddd\' width=\'200\' height=\'200\'/%3E%3Ctext x=\'50%25\' y=\'50%25\' text-anchor=\'middle\' dy=\'.3em\' fill=\'%23999\'%3EImmagine non disponibile%3C/text%3E%3C/svg%3E'"
                                    >
                                    <div class="absolute inset-0 bg-black bg-opacity-0 group-hover:bg-opacity-20 transition-opacity rounded-lg flex items-center justify-center">
                                        <span class="text-white opacity-0 group-hover:opacity-100 text-sm font-medium">Apri</span>
                                    </div>
                                </a>
                                <p class="mt-2 text-xs text-gray-600 dark:text-gray-400 truncate">{{ $asset->filename }}</p>
                                @if($asset->size_bytes)
                                    <p class="text-xs text-gray-500 dark:text-gray-500">{{ number_format($asset->size_bytes / 1024, 2) }} KB</p>
                                @endif
                            </div>
                        @else
                            <div class="border border-gray-200 dark:border-gray-700 rounded-lg p-4 text-center">
                                <p class="text-sm text-gray-500 dark:text-gray-400">{{ $asset->filename }}</p>
                                <p class="text-xs text-gray-400 dark:text-gray-500 mt-1">File non trovato</p>
                            </div>
                        @endif
                    @endforeach
                </div>
            @else
                <p class="text-sm text-gray-500 dark:text-gray-400">Nessuna immagine collegata a questa scheda</p>
            @endif
        </div>
    @else
        <div class="border-t border-gray-200 dark:border-gray-700 pt-6">
            <p class="text-sm text-gray-500 dark:text-gray-400">Seleziona una scheda per visualizzare i dettagli</p>
        </div>
    @endif
</div>
