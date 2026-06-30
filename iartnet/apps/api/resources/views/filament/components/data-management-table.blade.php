@if($component->showMainTable)
    <div class="mb-4">
        <div class="flex items-center justify-between">
            <h3 class="text-base font-semibold text-gray-900 dark:text-gray-100 m-0"></h3>
            <x-filament::button 
                wire:click="openImportToMaster"
                color="primary"
                size="sm"
            >
                IMPORT TO MASTER
            </x-filament::button>
        </div>
    </div>
    {{ $component->table }}
@endif
