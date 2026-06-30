<x-filament-panels::page>
    <div class="space-y-10">
        <div class="rounded-lg border border-gray-200 bg-white p-8 dark:border-gray-700 dark:bg-gray-800">
            <p class="text-sm leading-relaxed text-gray-600 dark:text-gray-400">
                Il worker di traduzione processa una scheda alla volta: legge i testi in italiano da <code>i18n_texts</code>,
                li invia al servizio Libre Translate e inserisce/aggiorna i testi in inglese (lang = en). Le schede vengono
                selezionate dalla tabella <code>records</code> con <code>is_translated = false</code>.
            </p>
            <p class="mt-6 text-sm text-gray-600 dark:text-gray-400">
                <strong>Stato attuale:</strong>
                @if($this->isWorkerEnabled())
                    <span class="font-medium text-success-600 dark:text-success-400">Worker attivo</span>
                @else
                    <span class="font-medium text-gray-500 dark:text-gray-400">Worker fermo</span>
                @endif
            </p>
        </div>

        {{-- Pulsanti in stile Filament --}}
        <div class="flex flex-wrap gap-6 pt-2">
            <x-filament::button
                wire:click="startTranslation"
                color="success"
                size="lg"
            >
                Start Translation
            </x-filament::button>
            <x-filament::button
                wire:click="stopTranslation"
                color="gray"
                size="lg"
            >
                Stop Translation
            </x-filament::button>
        </div>
    </div>
</x-filament-panels::page>
