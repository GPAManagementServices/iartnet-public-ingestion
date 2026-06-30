<div class="rounded-xl bg-white dark:bg-gray-800 shadow-sm border border-gray-200 dark:border-gray-700 overflow-hidden flex flex-col min-h-[calc(100vh-250px)]">
    <!-- Header: Card details + Return To Main Table -->
    <div class="bg-gray-50 dark:bg-gray-900 border-b border-gray-200 dark:border-gray-700 px-4 py-4 sm:px-6">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div class="flex-1 min-w-0">
                <h3 class="text-base font-semibold text-gray-900 dark:text-gray-100 m-0">Card details</h3>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400 mb-0">Record ID: <span class="font-mono font-medium">{{ $this->getCardDetailRecordId() }}</span></p>
            </div>
            <button
                type="button"
                wire:click="returnToMainTable"
                class="inline-flex items-center px-4 py-2 text-sm font-medium rounded-md border border-gray-300 text-gray-700 bg-white hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:bg-gray-700 dark:hover:bg-gray-600 cursor-pointer"
            >
                ← Return To Main Table
            </button>
        </div>
    </div>

    <!-- Tabs: 3 categorie dati + Images Preview (tab immagini invariato) -->
    <div class="border-b border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 px-4 sm:px-6">
        <x-filament::tabs>
            <x-filament::tabs.item
                :active="$this->cardDetailActiveTab === 'originalFields'"
                wire:click="$set('cardDetailActiveTab', 'originalFields')"
            >
                Original Fields
            </x-filament::tabs.item>
            <x-filament::tabs.item
                :active="$this->cardDetailActiveTab === 'metadata'"
                wire:click="$set('cardDetailActiveTab', 'metadata')"
            >
                Metadata
            </x-filament::tabs.item>
            <x-filament::tabs.item
                :active="$this->cardDetailActiveTab === 'addedFields'"
                wire:click="$set('cardDetailActiveTab', 'addedFields')"
            >
                Added Fields
            </x-filament::tabs.item>
            <x-filament::tabs.item
                :active="$this->cardDetailActiveTab === 'imagesPreview'"
                wire:click="$set('cardDetailActiveTab', 'imagesPreview')"
            >
                Images Preview
            </x-filament::tabs.item>
        </x-filament::tabs>
    </div>

    <!-- Tab: Original Fields / Metadata / Added Fields -->
    @if(in_array($this->cardDetailActiveTab, ['originalFields', 'metadata', 'addedFields'], true))
        <div class="p-4 sm:p-6 flex-1 overflow-y-auto min-h-[60vh]">
            @php $filteredRows = $this->getFilteredRecordDetailRows(); @endphp
            @if($this->recordTableRows !== null && count($this->recordTableRows) > 0)
                <div class="mb-4">
                    <x-filament::input.wrapper inline-prefix prefix-icon="heroicon-o-magnifying-glass">
                        <x-filament::input
                            type="search"
                            wire:model.live.debounce.500ms="recordDetailsSearch"
                            placeholder="Search in Key and Value..."
                            autocomplete="off"
                        />
                    </x-filament::input.wrapper>
                </div>
                <div class="mb-4 text-sm text-gray-500 dark:text-gray-400">
                    Total: <span class="font-medium">{{ count($filteredRows) }}</span> fields
                    @if($this->getRecordDetailTabRowCount() > 0)
                        <span class="text-gray-400 dark:text-gray-500">(of {{ $this->getRecordDetailTabRowCount() }} in this tab)</span>
                    @endif
                    @if(!empty($this->recordDetailsSearch))
                        <span class="text-amber-600 dark:text-amber-400">(filtered)</span>
                    @endif
                </div>
                @include('filament.resources.master-data-card-resource.pages.partials.card-detail-data-rows-table', ['filteredRows' => $filteredRows])
            @else
                <div class="text-center py-12">
                    <p class="text-sm font-medium text-gray-500 dark:text-gray-400">No record data found</p>
                </div>
            @endif
        </div>
    @endif

    <!-- Tab: Images Preview -->
    @if($this->cardDetailActiveTab === 'imagesPreview')
        <div class="p-4 sm:p-6 flex-1 overflow-y-auto min-h-[60vh]">
            @if($this->images->count() > 0)
                <div class="mb-4 text-sm text-gray-500 dark:text-gray-400">
                    Total: <span class="font-medium">{{ $this->images->count() }}</span> image(s)
                </div>
                <div class="mb-4 flex flex-wrap items-center gap-3">
                    @include('filament.resources.master-data-card-resource.pages.partials.card-detail-images-preview-controls', ['imageSelectId' => 'image-select-embed'])
                </div>
                <div class="border border-gray-200 dark:border-gray-600 rounded-lg overflow-hidden bg-gray-50 dark:bg-gray-900 flex items-center justify-center min-h-[400px]">
                    @php $imgUrl = $this->getSelectedImageUrl(); @endphp
                    @if($imgUrl)
                        <img src="{{ $imgUrl }}" alt="IIIF image" class="max-w-full max-h-[70vh] object-contain block" />
                    @else
                        <p class="text-sm text-gray-500 dark:text-gray-400">Unable to load image</p>
                    @endif
                </div>
            @else
                <div class="text-center py-12">
                    <p class="text-sm font-medium text-gray-500 dark:text-gray-400">No images found</p>
                </div>
            @endif
        </div>
    @endif
</div>
