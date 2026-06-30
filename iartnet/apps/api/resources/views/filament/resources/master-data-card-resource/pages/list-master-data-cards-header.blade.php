<div class="rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-800 dark:ring-white/10">
    <div class="p-4 sm:p-6">
        <div class="flex flex-nowrap items-center gap-4 overflow-x-auto">
            <div class="flex items-center gap-2 shrink-0 min-w-0">
                <label for="filter-institution" class="shrink-0 text-sm font-medium text-gray-950 dark:text-white whitespace-nowrap">
                    Institutions
                </label>
                <select
                    id="filter-institution"
                    wire:model="institutionId"
                    class="h-9 w-full min-w-[160px] max-w-[220px] rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm text-gray-950 shadow-sm focus:border-primary-500 focus:ring-0 focus:ring-primary-500/20 dark:border-gray-600 dark:bg-gray-700 dark:text-white dark:focus:border-primary-400 dark:focus:ring-primary-400/20"
                >
                    @forelse($institutions as $inst)
                        <option value="{{ $inst->id }}">{{ $inst->name }}</option>
                    @empty
                        <option value="">—</option>
                    @endforelse
                </select>
            </div>
            <div class="flex items-center gap-2 shrink-0 min-w-0">
                <label for="filter-card-type" class="shrink-0 text-sm font-medium text-gray-950 dark:text-white whitespace-nowrap">
                    CardType
                </label>
                <select
                    id="filter-card-type"
                    wire:model="cardType"
                    class="h-9 w-full min-w-[100px] max-w-[140px] rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm text-gray-950 shadow-sm focus:border-primary-500 focus:ring-0 focus:ring-primary-500/20 dark:border-gray-600 dark:bg-gray-700 dark:text-white dark:focus:border-primary-400 dark:focus:ring-primary-400/20"
                >
                    <option value="TUTTE">ALL</option>
                    <option value="OA">OA</option>
                    <option value="D">D</option>
                    <option value="F">F</option>
                    <option value="S">S</option>
                    <option value="MI">MI</option>
                    <option value="MIDF">MIDF</option>
                    <option value="MINV">MINV</option>
                    <option value="SBN">SBN</option>
                    <option value="JSON">JSON</option>
                    <option value="INTERVISTA">INTERVIEW</option>
                    <option value="SALON">SALON_N</option>
                </select>
            </div>
            {{--
                Stesso componente e convenzioni del pulsante tabella "Publish all draft" (fi-btn, icona + label, stati hover/focus/dark).
                color="sky" usa la palette registrata nel panel (allineata al precedente bg-sky-600).
            --}}
            <div class="shrink-0">
                <x-filament::button
                    type="button"
                    wire:click="search"
                    color="sky"
                    icon="heroicon-o-magnifying-glass"
                >
                    SEARCH
                </x-filament::button>
            </div>
        </div>
    </div>
</div>
