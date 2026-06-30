<x-filament-panels::page>
    {{-- Add Media modal: primo figlio della page così fixed overlay non è dentro Record Details --}}
    @if($this->showAddMediaModal)
        <div
            class="fixed inset-0 z-[9999] bg-black/50 flex items-center justify-center p-4 overflow-y-auto"
            role="dialog"
            aria-modal="true"
            aria-labelledby="add-media-heading"
        >
            <div
                class="relative bg-white dark:bg-gray-800 rounded-xl shadow-2xl max-w-md w-full p-6"
                onclick="event.stopPropagation()"
            >
                <h4 id="add-media-heading" class="text-lg font-semibold m-0 mb-2 text-gray-900 dark:text-gray-100">Add Media</h4>
                <p class="text-sm text-gray-500 dark:text-gray-400 mb-4">Seleziona un file immagine (jpg, png, tiff, gif, webp, bmp).</p>
                <input
                    type="file"
                    wire:model.defer="addMediaFile"
                    accept=".jpg,.jpeg,.png,.tiff,.tif,.gif,.webp,.bmp"
                    class="block w-full mb-4 text-sm"
                />
                <p wire:loading wire:target="addMediaFile" class="text-sm text-gray-500 mt-1">Caricamento file...</p>
                @error('addMediaFile')
                    <p class="text-sm text-red-600 dark:text-red-400 mt-2">{{ $message }}</p>
                @enderror
                <div class="flex justify-end gap-2 mt-6">
                    <button
                        type="button"
                        wire:click="closeAddMediaModal"
                        class="px-4 py-2 text-sm font-medium rounded-md border border-gray-300 text-gray-700 bg-white hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:bg-gray-700 dark:hover:bg-gray-600 cursor-pointer"
                    >
                        Cancel
                    </button>
                    <button
                        type="button"
                        wire:click="confirmAddMedia"
                        wire:loading.attr="disabled"
                        wire:target="confirmAddMedia,addMediaFile"
                        class="px-4 py-2 text-sm font-medium rounded-md border border-blue-600 text-white bg-blue-600 hover:bg-blue-700 cursor-pointer disabled:opacity-50"
                    >
                        <span wire:loading.remove wire:target="confirmAddMedia,addMediaFile">Confirm</span>
                        <span wire:loading wire:target="confirmAddMedia">...</span>
                        <span wire:loading wire:target="addMediaFile">Attendere upload...</span>
                    </button>
                </div>
            </div>
        </div>
    @endif

    {{-- Modale conferma sincronizzazione (Import Data To Master) --}}
    @if($this->showSyncConfirmModal)
        <div
            class="fixed inset-0 z-[9999] bg-black/50 flex items-center justify-center p-4 overflow-y-auto"
            role="dialog"
            aria-modal="true"
            aria-labelledby="sync-confirm-heading"
        >
            <div
                class="relative bg-white dark:bg-gray-800 rounded-xl shadow-2xl max-w-md w-full p-6"
                onclick="event.stopPropagation()"
            >
                <h4 id="sync-confirm-heading" class="text-lg font-semibold m-0 mb-2 text-gray-900 dark:text-gray-100">Confirm synchronization</h4>
                <p class="text-sm text-gray-500 dark:text-gray-400 mb-4">Confirm to proceed with the data synchronization?</p>
                <div class="flex justify-end gap-2 mt-6">
                    <button
                        type="button"
                        wire:click="closeSyncConfirmModal"
                        class="px-4 py-2 text-sm font-medium rounded-md border border-gray-300 text-gray-700 bg-white hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:bg-gray-700 dark:hover:bg-gray-600 cursor-pointer"
                    >
                        Cancel
                    </button>
                    <button
                        type="button"
                        wire:click="confirmSyncAndProceed"
                        wire:loading.attr="disabled"
                        wire:target="confirmSyncAndProceed"
                        class="px-4 py-2 text-sm font-medium rounded-md border border-blue-600 text-white bg-blue-600 hover:bg-blue-700 cursor-pointer disabled:opacity-50"
                    >
                        <span wire:loading.remove wire:target="confirmSyncAndProceed">Confirm</span>
                        <span wire:loading wire:target="confirmSyncAndProceed">...</span>
                    </button>
                </div>
            </div>
        </div>
    @endif

    {{-- Modale Synchronize Images: scelta modalità copy | vips --}}
    @if($this->showSyncImagesModal)
        <div
            class="fixed inset-0 z-[9999] bg-black/50 flex items-center justify-center p-4 overflow-y-auto"
            role="dialog"
            aria-modal="true"
            aria-labelledby="sync-images-heading"
        >
            <div
                class="relative bg-white dark:bg-gray-800 rounded-xl shadow-2xl max-w-lg w-full p-6"
                onclick="event.stopPropagation()"
            >
                <h4 id="sync-images-heading" class="text-lg font-semibold m-0 mb-2 text-gray-900 dark:text-gray-100">Synchronize Images</h4>
                <p class="text-sm text-gray-500 dark:text-gray-400 mb-4">Scegli come copiare le immagini in IMAGES_ROOT e registrarle in web_resources.</p>
                <fieldset class="space-y-3 mb-4">
                    <legend class="sr-only">Modalità sincronizzazione immagini</legend>
                    <label class="flex items-start gap-2 cursor-pointer">
                        <input
                            type="radio"
                            name="syncImagesMode"
                            value="copy"
                            wire:model.live="syncImagesMode"
                            class="mt-1"
                        />
                        <span class="text-sm text-gray-700 dark:text-gray-300">
                            <strong>Copia diretta</strong> — comportamento attuale: file in IMAGES_ROOT come {uuid}.{ext} originale.
                        </span>
                    </label>
                    <label class="flex items-start gap-2 cursor-pointer">
                        <input
                            type="radio"
                            name="syncImagesMode"
                            value="vips"
                            wire:model.live="syncImagesMode"
                            class="mt-1"
                        />
                        <span class="text-sm text-gray-700 dark:text-gray-300">
                            <strong>Preparazione IIIF (vips)</strong> — TIFF tiled in IMAGES_ROOT come {uuid}.tif (piramide automatica se utile). Richiede libvips.
                        </span>
                    </label>
                </fieldset>
                <div class="flex justify-end gap-2 mt-6">
                    <button
                        type="button"
                        wire:click="closeSyncImagesModal"
                        class="px-4 py-2 text-sm font-medium rounded-md border border-gray-300 text-gray-700 bg-white hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:bg-gray-700 dark:hover:bg-gray-600 cursor-pointer"
                    >
                        Annulla
                    </button>
                    <button
                        type="button"
                        wire:click="confirmSyncImages"
                        wire:loading.attr="disabled"
                        wire:target="confirmSyncImages"
                        class="px-4 py-2 text-sm font-medium rounded-md border border-green-600 text-white bg-green-600 hover:bg-green-700 cursor-pointer disabled:opacity-50"
                    >
                        <span wire:loading.remove wire:target="confirmSyncImages">Avvia sincronizzazione</span>
                        <span wire:loading wire:target="confirmSyncImages">...</span>
                    </button>
                </div>
            </div>
        </div>
    @endif

    {{-- Modale Un-Synchronize Images (scope: tutti i record o lista record_id) --}}
    @if($this->showUnsynchronizeImagesModal)
        <div
            class="fixed inset-0 z-[9999] bg-black/50 flex items-center justify-center p-4 overflow-y-auto"
            role="dialog"
            aria-modal="true"
            aria-labelledby="unsynchronize-images-heading"
        >
            <div
                class="relative bg-white dark:bg-gray-800 rounded-xl shadow-2xl max-w-lg w-full p-6"
                onclick="event.stopPropagation()"
            >
                <h4 id="unsynchronize-images-heading" class="text-lg font-semibold m-0 mb-2 text-gray-900 dark:text-gray-100">Un-Synchronize Images</h4>
                <p class="text-sm text-gray-500 dark:text-gray-400 mb-4">Scegli l'ambito dell'operazione sulle righe della tabella asset (filename URL IIIF).</p>
                <fieldset class="space-y-3 mb-4">
                    <legend class="sr-only">Ambito operazione</legend>
                    <label class="flex items-start gap-2 cursor-pointer">
                        <input
                            type="radio"
                            name="unsynchronizeScope"
                            value="all"
                            wire:model.live="unsynchronizeScope"
                            class="mt-1"
                        />
                        <span class="text-sm text-gray-700 dark:text-gray-300">Applica a tutti i record</span>
                    </label>
                    <label class="flex items-start gap-2 cursor-pointer">
                        <input
                            type="radio"
                            name="unsynchronizeScope"
                            value="list"
                            wire:model.live="unsynchronizeScope"
                            class="mt-1"
                        />
                        <span class="text-sm text-gray-700 dark:text-gray-300">Applica alla lista seguente</span>
                    </label>
                </fieldset>
                @if($this->unsynchronizeScope === 'list')
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1" for="unsynchronize-record-ids">
                        record_id (separati da virgola)
                    </label>
                    <textarea
                        id="unsynchronize-record-ids"
                        wire:model.defer="unsynchronizeRecordIdsList"
                        rows="4"
                        placeholder="es. REC001, REC002, REC003"
                        class="block w-full rounded-md border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-sm text-gray-900 dark:text-gray-100 px-3 py-2"
                    ></textarea>
                @endif
                <div class="flex justify-end gap-2 mt-6">
                    <button
                        type="button"
                        wire:click="closeUnsynchronizeImagesModal"
                        class="px-4 py-2 text-sm font-medium rounded-md border border-gray-300 text-gray-700 bg-white hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:bg-gray-700 dark:hover:bg-gray-600 cursor-pointer"
                    >
                        Annulla
                    </button>
                    <button
                        type="button"
                        wire:click="confirmUnsynchronizeImages"
                        wire:loading.attr="disabled"
                        wire:target="confirmUnsynchronizeImages"
                        class="px-4 py-2 text-sm font-medium rounded-md border border-red-600 text-white bg-red-600 hover:bg-red-700 cursor-pointer disabled:opacity-50"
                    >
                        <span wire:loading.remove wire:target="confirmUnsynchronizeImages">Conferma</span>
                        <span wire:loading wire:target="confirmUnsynchronizeImages">...</span>
                    </button>
                </div>
            </div>
        </div>
    @endif

    <div class="{{ $this->showMainTable ? 'block' : 'hidden' }}">
        <form>
            {{ $this->form }}
        </form>
    </div>
    
    @if($this->showDetailsTable && $this->selectedRecordId && $this->targetSchema)
        @php
            $detailsData = $this->getDetailsRecords();
            $records = $detailsData['records'];
            $total = $detailsData['total'];
            $currentPage = $detailsData['currentPage'];
            $lastPage = $detailsData['lastPage'];
            $perPage = $detailsData['perPage'];
            $assetImages = $this->getAssetImages();
            $addedFieldsData = $this->getAddedFieldsRecords();
            $addedFieldsRecords = $addedFieldsData['records'];
            $addedFieldsTotal = $addedFieldsData['total'];
            $addedFieldsCurrentPage = $addedFieldsData['currentPage'];
            $addedFieldsLastPage = $addedFieldsData['lastPage'];
            $addedFieldsPerPage = $addedFieldsData['perPage'];
        @endphp
        
        <div class="mt-6 min-h-[calc(100vh-200px)]">
            <div class="rounded-xl bg-white dark:bg-gray-800 shadow-sm border border-gray-200 dark:border-gray-700 overflow-hidden flex flex-col h-full min-h-[calc(100vh-250px)]">
                <!-- Header Section -->
                <div class="bg-gray-50 dark:bg-gray-900 border-b border-gray-200 dark:border-gray-700 px-4 py-4 sm:px-6">
                    <div class="flex flex-wrap items-center justify-between gap-4">
                        <div class="flex-1 min-w-0">
                            <h3 class="text-base font-semibold text-gray-900 dark:text-gray-100 m-0">Record Details</h3>
                            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400 mb-0">Record ID: <span class="font-mono font-medium">{{ $this->selectedRecordId }}</span></p>
                        </div>
                        <div class="flex items-center gap-2">
                            <button
                                type="button"
                                wire:click="openAddMediaModal"
                                class="inline-flex items-center px-4 py-2 text-sm font-medium rounded-md border border-blue-600 text-white bg-blue-600 hover:bg-blue-700 cursor-pointer"
                            >
                                Add Media
                            </button>
                            <button
                                type="button"
                                wire:click="backToMain"
                                class="inline-flex items-center px-4 py-2 text-sm font-medium rounded-md border border-gray-300 text-gray-700 bg-white hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:bg-gray-700 dark:hover:bg-gray-600 cursor-pointer"
                            >
                                ← Return to Main Table
                            </button>
                        </div>
                    </div>
                </div>
                
                <!-- Tabs Navigation -->
                <div class="border-b border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 px-4 sm:px-6">
                    <x-filament::tabs>
                        <x-filament::tabs.item
                            :active="$this->activeDetailsTab === 'details'"
                            wire:click="$set('activeDetailsTab', 'details')"
                        >
                            Record Details Table
                        </x-filament::tabs.item>
                        <x-filament::tabs.item
                            :active="$this->activeDetailsTab === 'images'"
                            wire:click="$set('activeDetailsTab', 'images')"
                        >
                            Images Preview
                        </x-filament::tabs.item>
                        <x-filament::tabs.item
                            :active="$this->activeDetailsTab === 'addedFields'"
                            wire:click="$set('activeDetailsTab', 'addedFields')"
                        >
                            Added Fields
                        </x-filament::tabs.item>
                    </x-filament::tabs>
                </div>
                
                <!-- Tab Content: Record Details Table -->
                @if($this->activeDetailsTab === 'details')
                    <div class="p-4 sm:p-6 flex-1 overflow-y-auto min-h-[60vh]">
                        <div class="mb-4">
                            <x-filament::input.wrapper
                                inline-prefix
                                prefix-icon="heroicon-o-magnifying-glass"
                            >
                                <x-filament::input
                                    type="search"
                                    wire:model.live.debounce.500ms="detailsSearch"
                                    placeholder="Search in XPath and Valore..."
                                    autocomplete="off"
                                />
                            </x-filament::input.wrapper>
                        </div>
                        <div class="mb-4 text-sm text-gray-500 dark:text-gray-400">
                            Total: <span class="font-medium">{{ $total }}</span> records
                            @if(!empty($this->detailsSearch))
                                <span class="text-amber-600 dark:text-amber-400">(filtered)</span>
                            @endif
                        </div>
                        @if($records->count() > 0)
                            <div class="overflow-x-auto -mx-4 sm:-mx-6">
                                <div class="inline-block min-w-full align-middle">
                                    <div class="overflow-hidden">
                                        <table class="min-w-full border-collapse text-xs leading-tight">
                                            <thead class="bg-gray-50 dark:bg-gray-900">
                                                <tr>
                                                    <th scope="col" class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider border-b border-gray-200 dark:border-gray-700">XPath</th>
                                                    <th scope="col" class="px-4 py-3 text-center text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider border-b border-gray-200 dark:border-gray-700">Occurrence Index</th>
                                                    <th scope="col" class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider border-b border-gray-200 dark:border-gray-700">Valore</th>
                                                </tr>
                                            </thead>
                                            <tbody class="bg-white dark:bg-gray-800">
                                                @foreach($records as $record)
                                                    <tr class="border-b border-gray-200 dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-700/50 transition-colors">
                                                        <td class="whitespace-nowrap px-2 py-1 text-xs font-mono text-gray-900 dark:text-gray-100">{{ $record->xpath }}</td>
                                                        <td class="whitespace-nowrap px-2 py-1 text-xs text-center text-gray-700 dark:text-gray-300">{{ $record->occurrence_idx }}</td>
                                                        <td class="px-2 py-1 text-xs text-gray-700 dark:text-gray-300">{{ Str::limit($record->value_text, 200) }}</td>
                                                    </tr>
                                                @endforeach
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                            @if($lastPage > 1)
                                <div class="mt-4 flex flex-wrap items-center justify-between gap-4 border-t border-gray-200 dark:border-gray-700 pt-4">
                                    <div class="text-sm text-gray-700 dark:text-gray-300">
                                        <span class="font-medium">Showing</span> {{ (($currentPage - 1) * $perPage) + 1 }}
                                        <span class="font-medium">to</span> {{ min($currentPage * $perPage, $total) }}
                                        <span class="font-medium">of</span> {{ $total }}
                                        <span class="font-medium">results</span>
                                    </div>
                                    <div class="flex items-center gap-1">
                                        @if($currentPage > 1)
                                            <button
                                                type="button"
                                                wire:click="goToDetailsPage({{ $currentPage - 1 }})"
                                                class="inline-flex items-center px-3 py-1.5 text-sm font-medium rounded-md border border-gray-300 text-gray-700 bg-white hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:bg-gray-700 dark:hover:bg-gray-600 cursor-pointer"
                                            >
                                                Previous
                                            </button>
                                        @endif
                                        @for($i = max(1, $currentPage - 2); $i <= min($lastPage, $currentPage + 2); $i++)
                                            <button
                                                type="button"
                                                wire:click="goToDetailsPage({{ $i }})"
                                                class="inline-flex items-center px-3 py-1.5 text-sm font-medium rounded-md cursor-pointer {{ $i === $currentPage ? 'border border-gray-700 bg-gray-800 text-white hover:bg-gray-700 dark:bg-gray-200 dark:text-gray-900 dark:border-gray-300 dark:hover:bg-gray-300' : 'border border-gray-300 text-gray-700 bg-white hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:bg-gray-700 dark:hover:bg-gray-600' }}"
                                            >
                                                {{ $i }}
                                            </button>
                                        @endfor
                                        @if($currentPage < $lastPage)
                                            <button
                                                type="button"
                                                wire:click="goToDetailsPage({{ $currentPage + 1 }})"
                                                class="inline-flex items-center px-3 py-1.5 text-sm font-medium rounded-md border border-gray-300 text-gray-700 bg-white hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:bg-gray-700 dark:hover:bg-gray-600 cursor-pointer"
                                            >
                                                Next
                                            </button>
                                        @endif
                                    </div>
                                </div>
                            @endif
                        @else
                            <div class="text-center py-12">
                                <p class="text-sm font-medium text-gray-500 dark:text-gray-400">No records found</p>
                            </div>
                        @endif
                    </div>
                @endif
                
                <!-- Tab Content: Images Preview -->
                @if($this->activeDetailsTab === 'images')
                    <div class="p-4 sm:p-6 flex-1 overflow-y-auto min-h-[60vh]">
                        @if($assetImages->count() > 0)
                            <div class="mb-4 text-sm text-gray-500 dark:text-gray-400">
                                Total: <span class="font-medium">{{ $assetImages->count() }}</span> images
                            </div>
                            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-6 justify-items-center max-w-full">
                                @foreach($assetImages as $asset)
                                    @php
                                        // Due modalità: promoted = true → URL IIIF (normalizzato); promoted = false → data URI da ingestion
                                        $previewSrc = $this->getImagePreviewSrc($asset);
                                    @endphp
                                    @if($previewSrc)
                                        <div class="border border-gray-200 dark:border-gray-600 rounded-lg overflow-hidden bg-white dark:bg-gray-800 w-full max-w-[500px]">
                                            <div class="w-full min-h-[280px] aspect-[4/3] bg-gray-50 dark:bg-gray-900 flex items-center justify-center overflow-hidden">
                                                <img
                                                    src="{{ $previewSrc }}"
                                                    alt="{{ $asset->filename }}"
                                                    class="max-w-full max-h-full object-contain block"
                                                    loading="lazy"
                                                />
                                            </div>
                                            <div class="p-4 border-t border-gray-200 dark:border-gray-600">
                                                <p class="text-sm text-gray-700 dark:text-gray-300 m-0 break-all font-mono text-center">{{ $asset->filename }}</p>
                                            </div>
                                        </div>
                                    @else
                                        <div class="border border-gray-200 dark:border-gray-600 rounded-lg overflow-hidden bg-white dark:bg-gray-800 w-full max-w-[500px]">
                                            <div class="w-full min-h-[280px] aspect-[4/3] bg-gray-50 dark:bg-gray-900 flex items-center justify-center">
                                                <p class="text-sm text-gray-500 dark:text-gray-400 text-center p-4">Image not found</p>
                                            </div>
                                            <div class="p-4 border-t border-gray-200 dark:border-gray-600">
                                                <p class="text-sm text-gray-700 dark:text-gray-300 m-0 break-all font-mono text-center">{{ $asset->filename }}</p>
                                            </div>
                                        </div>
                                    @endif
                                @endforeach
                            </div>
                        @else
                            <div class="text-center py-12">
                                <p class="text-sm font-medium text-gray-500 dark:text-gray-400">No images found</p>
                            </div>
                        @endif
                    </div>
                @endif
                
                <!-- Tab Content: Added Fields -->
                @if($this->activeDetailsTab === 'addedFields')
                    <div class="p-4 sm:p-6 flex-1 overflow-y-auto min-h-[60vh]">
                        <div class="mb-4">
                            <x-filament::input.wrapper
                                inline-prefix
                                prefix-icon="heroicon-o-magnifying-glass"
                            >
                                <x-filament::input
                                    type="search"
                                    wire:model.live.debounce.500ms="addedFieldsSearch"
                                    placeholder="Search in Field Name and Value..."
                                    autocomplete="off"
                                />
                            </x-filament::input.wrapper>
                        </div>
                        <div class="mb-4 text-sm text-gray-500 dark:text-gray-400">
                            Total: <span class="font-medium">{{ $addedFieldsTotal }}</span> records
                            @if(!empty($this->addedFieldsSearch))
                                <span class="text-amber-600 dark:text-amber-400">(filtered)</span>
                            @endif
                        </div>
                        @if($addedFieldsRecords->count() > 0)
                            <div class="overflow-x-auto -mx-4 sm:-mx-6">
                                <div class="inline-block min-w-full align-middle">
                                    <div class="overflow-hidden">
                                        <table class="min-w-full border-collapse text-xs leading-tight">
                                            <thead class="bg-gray-50 dark:bg-gray-900">
                                                <tr>
                                                    <th scope="col" class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider border-b border-gray-200 dark:border-gray-700">Field Name</th>
                                                    <th scope="col" class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider border-b border-gray-200 dark:border-gray-700">Value</th>
                                                </tr>
                                            </thead>
                                            <tbody class="bg-white dark:bg-gray-800">
                                                @foreach($addedFieldsRecords as $record)
                                                    <tr class="border-b border-gray-200 dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-700/50 transition-colors">
                                                        <td class="whitespace-nowrap px-2 py-1 text-xs font-mono text-gray-900 dark:text-gray-100">{{ $record->field_name }}</td>
                                                        <td class="px-2 py-1 text-xs text-gray-700 dark:text-gray-300">{{ Str::limit($record->value_text, 200) }}</td>
                                                    </tr>
                                                @endforeach
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                            @if($addedFieldsLastPage > 1)
                                <div class="mt-4 flex flex-wrap items-center justify-between gap-4 border-t border-gray-200 dark:border-gray-700 pt-4">
                                    <div class="text-sm text-gray-700 dark:text-gray-300">
                                        <span class="font-medium">Showing</span> {{ (($addedFieldsCurrentPage - 1) * $addedFieldsPerPage) + 1 }}
                                        <span class="font-medium">to</span> {{ min($addedFieldsCurrentPage * $addedFieldsPerPage, $addedFieldsTotal) }}
                                        <span class="font-medium">of</span> {{ $addedFieldsTotal }}
                                        <span class="font-medium">results</span>
                                    </div>
                                    <div class="flex items-center gap-1">
                                        @if($addedFieldsCurrentPage > 1)
                                            <button
                                                type="button"
                                                wire:click="goToAddedFieldsPage({{ $addedFieldsCurrentPage - 1 }})"
                                                class="inline-flex items-center px-3 py-1.5 text-sm font-medium rounded-md border border-gray-300 text-gray-700 bg-white hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:bg-gray-700 dark:hover:bg-gray-600 cursor-pointer"
                                            >
                                                Previous
                                            </button>
                                        @endif
                                        @for($i = max(1, $addedFieldsCurrentPage - 2); $i <= min($addedFieldsLastPage, $addedFieldsCurrentPage + 2); $i++)
                                            <button
                                                type="button"
                                                wire:click="goToAddedFieldsPage({{ $i }})"
                                                class="inline-flex items-center px-3 py-1.5 text-sm font-medium rounded-md cursor-pointer {{ $i === $addedFieldsCurrentPage ? 'border border-gray-700 bg-gray-800 text-white hover:bg-gray-700 dark:bg-gray-200 dark:text-gray-900 dark:border-gray-300 dark:hover:bg-gray-300' : 'border border-gray-300 text-gray-700 bg-white hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:bg-gray-700 dark:hover:bg-gray-600' }}"
                                            >
                                                {{ $i }}
                                            </button>
                                        @endfor
                                        @if($addedFieldsCurrentPage < $addedFieldsLastPage)
                                            <button
                                                type="button"
                                                wire:click="goToAddedFieldsPage({{ $addedFieldsCurrentPage + 1 }})"
                                                class="inline-flex items-center px-3 py-1.5 text-sm font-medium rounded-md border border-gray-300 text-gray-700 bg-white hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:bg-gray-700 dark:hover:bg-gray-600 cursor-pointer"
                                            >
                                                Next
                                            </button>
                                        @endif
                                    </div>
                                </div>
                            @endif
                        @else
                            <div class="text-center py-12">
                                <p class="text-sm font-medium text-gray-500 dark:text-gray-400">No records found</p>
                            </div>
                        @endif
                    </div>
                @endif
            </div>
        </div>

    @endif
    
    @if($this->showImportToMaster && $this->targetSchema)
        <div class="mt-6 min-h-[calc(100vh-200px)]">
            <div class="rounded-xl bg-white dark:bg-gray-800 shadow-sm border border-gray-200 dark:border-gray-700 overflow-hidden flex flex-col h-full min-h-[calc(100vh-250px)]">
                <!-- Header Section -->
                <div class="bg-gray-50 dark:bg-gray-900 border-b border-gray-200 dark:border-gray-700 px-4 py-4 sm:px-6">
                    <div class="flex flex-wrap items-center justify-between gap-4">
                        <div class="flex-1 min-w-0">
                            <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100 m-0">IMPORT DATA TO MASTER</h3>
                            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400 mb-0">Schema: <span class="font-mono font-medium">{{ $this->targetSchema }}</span></p>
                        </div>
                        <x-filament::button 
                            wire:click="backToWizard"
                            color="gray"
                            size="sm"
                        >
                            ← Back to Main table
                        </x-filament::button>
                    </div>
                </div>
                
                <!-- Content Section -->
                <div class="p-6 flex-1 overflow-y-auto min-h-[60vh]">
                    <div class="mb-6 flex flex-wrap gap-4 items-center">
                        <x-filament::button 
                            wire:click="openSyncConfirmModal('importDataToMasterDB')"
                            color="primary"
                            size="lg"
                        >
                            PROCEDE TO IMPORT DATA
                        </x-filament::button>
                        <x-filament::button 
                            wire:click="openSyncConfirmModal('importAddedFieldsMasterDB')"
                            color="info"
                            size="lg"
                        >
                            Synchronize Added Fields
                        </x-filament::button>
                        <x-filament::button 
                            wire:click="openSyncImagesModal"
                            color="success"
                            size="lg"
                        >
                            Synchronize Images
                        </x-filament::button>
                        <x-filament::button 
                            wire:click="openUnsynchronizeImagesModal"
                            color="danger"
                            size="lg"
                        >
                            Un-Synchronize Images
                        </x-filament::button>
                    </div>
                    
                    <!-- Information Box -->
                    <div class="border border-gray-200 dark:border-gray-600 rounded-lg bg-gray-50 dark:bg-gray-900 p-6">
                        <h4 class="text-sm font-semibold text-gray-900 dark:text-gray-100 m-0 mb-2">Information</h4>
                        @if($this->importResults)
                            @if(!empty($this->importResults['background_started']))
                                <p class="text-sm text-blue-600 dark:text-blue-400 m-0">
                                    @if(!empty($this->importResults['sync_mode']))
                                        Sincronizzazione immagini avviata in background
                                        (modalità: {{ $this->importResults['sync_mode'] === 'vips' ? 'Preparazione IIIF (vips)' : 'Copia diretta' }}).
                                        Controlla i log del worker per l'esito.
                                    @else
                                        Cards import to Master procedure started, will be executed in background
                                    @endif
                                </p>
                            @elseif($this->importResults['success'])
                                <div class="text-sm text-green-600 dark:text-green-400 mb-3">
                                    <strong>✓ Importazione completata con successo</strong>
                                </div>
                                <div class="text-sm text-gray-700 dark:text-gray-300 leading-relaxed">
                                    <div class="mb-2">
                                        <strong>Numero record processati:</strong> {{ $this->importResults['processed'] }}
                                    </div>
                                    <div class="mb-2">
                                        <strong class="text-green-600 dark:text-green-400">Numero record inseriti correttamente:</strong> {{ $this->importResults['success_count'] }}
                                    </div>
                                    <div class="mb-2">
                                        <strong class="text-red-600 dark:text-red-400">Numero record falliti in inserimento:</strong> {{ $this->importResults['error_count'] ?? 0 }}
                                    </div>
                                    @if(isset($this->importResults['skipped_count']) && $this->importResults['skipped_count'] > 0)
                                        <div class="mb-2">
                                            <strong class="text-amber-600 dark:text-amber-400">Numero record saltati:</strong> {{ $this->importResults['skipped_count'] }}
                                        </div>
                                    @endif
                                    @if(isset($this->importResults['warnings']) && $this->importResults['warnings'] > 0)
                                        <div class="mb-2">
                                            <strong class="text-amber-600 dark:text-amber-400">Warning:</strong> {{ $this->importResults['warnings'] }}
                                        </div>
                                    @endif
                                </div>
                            @else
                                <div class="text-sm text-red-600 dark:text-red-400 mb-3">
                                    <strong>✗ Errore durante l'importazione</strong>
                                </div>
                                <div class="text-sm text-gray-700 dark:text-gray-300 leading-relaxed">
                                    @if($this->importResults['error'])
                                        <div class="mb-2">
                                            <strong>Errore:</strong> {{ $this->importResults['error'] }}
                                        </div>
                                    @endif
                                    <div class="mb-2">
                                        <strong>Numero record processati:</strong> {{ $this->importResults['processed'] }}
                                    </div>
                                    <div class="mb-2">
                                        <strong class="text-green-600 dark:text-green-400">Numero record inseriti correttamente:</strong> {{ $this->importResults['success_count'] ?? 0 }}
                                    </div>
                                    <div class="mb-2">
                                        <strong class="text-red-600 dark:text-red-400">Numero record falliti:</strong> {{ $this->importResults['error_count'] ?? 0 }}
                                    </div>
                                    @if(isset($this->importResults['skipped_count']) && $this->importResults['skipped_count'] > 0)
                                        <div class="mb-2">
                                            <strong class="text-amber-600 dark:text-amber-400">Numero record saltati:</strong> {{ $this->importResults['skipped_count'] }}
                                        </div>
                                    @endif
                                </div>
                            @endif
                            
                            {{-- Dettagli errori per importazione immagini --}}
                            @if(isset($this->importResults['error_details']) && count($this->importResults['error_details']) > 0)
                                <div class="mt-4 pt-4 border-t border-gray-200 dark:border-gray-600">
                                    <h5 class="text-sm font-semibold text-red-600 dark:text-red-400 m-0 mb-3">
                                        Dettagli Errori ({{ count($this->importResults['error_details']) }})
                                    </h5>
                                    <div class="max-h-[400px] overflow-y-auto text-[0.8125rem]">
                                        @foreach($this->importResults['error_details'] as $index => $errorDetail)
                                            <div class="mb-3 p-3 bg-white dark:bg-gray-800 border border-red-200 dark:border-red-900 rounded-md">
                                                <div class="mb-1">
                                                    <strong class="text-red-800 dark:text-red-300">Errore #{{ $index + 1 }}:</strong>
                                                </div>
                                                <div class="mb-1 text-gray-700 dark:text-gray-300">
                                                    <strong>Filename:</strong> {{ $errorDetail['filename'] ?? 'N/A' }}
                                                </div>
                                                @if(isset($errorDetail['record_id']))
                                                    <div class="mb-1 text-gray-700 dark:text-gray-300">
                                                        <strong>Record ID:</strong> {{ $errorDetail['record_id'] }}
                                                    </div>
                                                @endif
                                                @if(isset($errorDetail['asset_id']))
                                                    <div class="mb-1 text-gray-700 dark:text-gray-300">
                                                        <strong>Asset ID:</strong> {{ $errorDetail['asset_id'] }}
                                                    </div>
                                                @endif
                                                @if(isset($errorDetail['image_path']))
                                                    <div class="mb-1 text-gray-700 dark:text-gray-300">
                                                        <strong>Path:</strong> <span class="font-mono text-xs break-all">{{ $errorDetail['image_path'] }}</span>
                                                    </div>
                                                @endif
                                                <div class="text-red-600 dark:text-red-400 font-medium">
                                                    <strong>Messaggio:</strong> {{ $errorDetail['error'] ?? 'Errore sconosciuto' }}
                                                </div>
                                            </div>
                                        @endforeach
                                    </div>
                                </div>
                            @endif
                        @else
                            <p class="text-sm text-gray-500 dark:text-gray-400 m-0">
                                Clicca su "PROCEDE TO IMPORT DATA", "Synchronize Images" o "Un-Synchronize Images" per avviare l'operazione.
                            </p>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    @endif
</x-filament-panels::page>
