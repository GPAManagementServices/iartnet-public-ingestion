@if($this->showAddMediaModal)
    <div
        class="fixed inset-0 z-[9999] bg-black/50 flex items-center justify-center p-4 overflow-y-auto"
        role="dialog"
        aria-modal="true"
        aria-labelledby="add-media-narration-heading"
    >
        <div
            class="relative bg-white dark:bg-gray-800 rounded-xl shadow-2xl max-w-md w-full p-6"
            onclick="event.stopPropagation()"
        >
            <h4 id="add-media-narration-heading" class="text-lg font-semibold m-0 mb-2 text-gray-900 dark:text-gray-100">Add Media</h4>
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
