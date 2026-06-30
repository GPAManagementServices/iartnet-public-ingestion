<x-filament-panels::page>
    <div class="space-y-6">
        {{ $this->form }}
        
        <div class="mt-6">
            <h2 class="text-lg font-semibold mb-4">Dettagli estratti</h2>
            {{ $this->table }}
        </div>
    </div>
</x-filament-panels::page>
