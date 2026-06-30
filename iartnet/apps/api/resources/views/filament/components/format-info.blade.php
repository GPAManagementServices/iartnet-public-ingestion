<div class="p-4 bg-gray-50 dark:bg-gray-800 rounded-lg border">
    <h3 class="font-semibold mb-2">Informazioni Formato</h3>
    <p class="mb-2">
        <strong>Formato:</strong> 
        <span class="px-2 py-1 rounded text-sm font-medium
            @if($format === 'ICCD') bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200
            @elseif($format === 'SBN') bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200
            @elseif($format === 'JSON') bg-purple-100 text-purple-800 dark:bg-purple-900 dark:text-purple-200
            @else bg-gray-100 text-gray-800 dark:bg-gray-900 dark:text-gray-200
            @endif">
            {{ $format ?? 'Non rilevato' }}
        </span>
    </p>
    @if($format === 'SBN' || $format === 'JSON')
        <p class="text-sm text-gray-600 dark:text-gray-400 mt-2">
            Per questo formato è disponibile solo l'importazione dati. La validazione XSD non è disponibile.
        </p>
    @endif
</div>
