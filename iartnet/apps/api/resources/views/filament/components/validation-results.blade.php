<div class="space-y-4">
    @if($hasValidationErrors)
        <div class="rounded-lg bg-danger-50 dark:bg-danger-900/20 p-4">
            <div class="flex">
                <div class="flex-shrink-0">
                    <x-filament::icon icon="heroicon-o-exclamation-triangle" class="h-5 w-5 text-danger-600 dark:text-danger-400" />
                </div>
                <div class="ml-3">
                    <h3 class="text-sm font-medium text-danger-800 dark:text-danger-200">
                        Validazione completata con errori
                    </h3>
                </div>
            </div>
        </div>
    @else
        <div class="rounded-lg bg-success-50 dark:bg-success-900/20 p-4">
            <div class="flex">
                <div class="flex-shrink-0">
                    <x-filament::icon icon="heroicon-o-check-circle" class="h-5 w-5 text-success-600 dark:text-success-400" />
                </div>
                <div class="ml-3">
                    <h3 class="text-sm font-medium text-success-800 dark:text-success-200">
                        Validazione completata senza errori
                    </h3>
                </div>
            </div>
        </div>
    @endif

    <div class="space-y-3">
        @foreach($validationResults as $file => $issues)
            <div class="rounded-lg border border-gray-200 dark:border-gray-700 p-4">
                <h4 class="text-sm font-semibold text-gray-900 dark:text-gray-100 mb-2">
                    {{ basename($file) }}
                </h4>
                @if(empty($issues))
                    <p class="text-sm text-gray-600 dark:text-gray-400">Nessun problema rilevato</p>
                @else
                    <div class="space-y-2">
                        @foreach($issues as $issue)
                            @php
                                $severity = $issue['severity'] ?? 'error';
                                $message = $issue['message'] ?? '';
                                $line = $issue['line'] ?? null;
                                $column = $issue['column'] ?? null;
                                $schedaId = $issue['scheda_id'] ?? null;
                            @endphp
                            <div class="flex items-start space-x-2">
                                <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium
                                    @if($severity === 'error') bg-danger-100 text-danger-800 dark:bg-danger-900 dark:text-danger-200
                                    @elseif($severity === 'warning') bg-warning-100 text-warning-800 dark:bg-warning-900 dark:text-warning-200
                                    @else bg-gray-100 text-gray-800 dark:bg-gray-800 dark:text-gray-200
                                    @endif">
                                    {{ strtoupper($severity) }}
                                </span>
                                <div class="flex-1 text-sm text-gray-700 dark:text-gray-300">
                                    <p>{{ $message }}</p>
                                    @if($line || $column || $schedaId)
                                        <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                                            @if($schedaId) Scheda: {{ $schedaId }} @endif
                                            @if($line) Linea: {{ $line }} @endif
                                            @if($column) Colonna: {{ $column }} @endif
                                        </p>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        @endforeach
    </div>
</div>
