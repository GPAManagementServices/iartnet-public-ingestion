<div class="space-y-2">
    <div class="p-4 bg-gray-50 dark:bg-gray-800 rounded-lg border">
        <h3 class="font-semibold mb-2">Controllo Dati Caricati</h3>
        <p class="mb-2">
            <strong>Formato Rilevato:</strong> 
            <span class="px-2 py-1 rounded text-sm font-medium
                @if($format === 'ICCD') bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200
                @elseif($format === 'SBN') bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200
                @elseif($format === 'JSON') bg-purple-100 text-purple-800 dark:bg-purple-900 dark:text-purple-200
                @else bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200
                @endif">
                {{ $format ?? 'Non rilevato' }}
            </span>
        </p>
        @if($format === 'Formato non accettato')
            <p class="text-sm text-red-600 dark:text-red-400 mt-2">
                ⚠️ Non sarà possibile procedere allo Step 3 con questo formato.
            </p>
        @elseif(in_array($format ?? '', ['ICCD', 'SBN', 'JSON'], true))
            <p class="text-sm text-green-600 dark:text-green-400 mt-2">
                ✓ Formato accettato. È possibile procedere allo Step 3.
            </p>
        @endif
    </div>
    <div class="mt-4">
        <p><strong>Total Files:</strong> {{ $totalFiles }}</p>
        <p><strong>XML Files:</strong> {{ $xmlFiles }}</p>
        @if(isset($jsonFiles))
            <p><strong>JSON Files:</strong> {{ $jsonFiles }}</p>
        @endif
        <p><strong>Media Files:</strong> {{ $mediaFiles }}</p>
    </div>
</div>
