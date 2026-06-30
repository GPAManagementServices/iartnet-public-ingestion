@if($this->images->count() > 1)
    <label for="{{ $imageSelectId }}" class="text-sm font-medium text-gray-700 dark:text-gray-300">Image:</label>
    <select
        id="{{ $imageSelectId }}"
        wire:model.live="selectedImageIndex"
        class="py-1.5 px-3 border border-gray-300 dark:border-gray-600 rounded-md text-sm min-w-[200px] bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100"
    >
        @foreach($this->images as $idx => $img)
            <option value="{{ $idx }}">Image {{ $idx + 1 }}</option>
        @endforeach
    </select>
    <button
        type="button"
        wire:click="moveSelectedImageUp"
        wire:loading.attr="disabled"
        wire:target="moveSelectedImageUp"
        @disabled(! $this->canMoveSelectedImageUp())
        class="inline-flex items-center justify-center rounded-md border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 px-3 py-1.5 text-sm font-medium text-gray-900 dark:text-gray-100 hover:bg-gray-50 dark:hover:bg-gray-600 disabled:opacity-50 cursor-pointer disabled:cursor-not-allowed"
    >
        <span wire:loading.remove wire:target="moveSelectedImageUp">Move up</span>
        <span wire:loading wire:target="moveSelectedImageUp" class="text-xs">Saving…</span>
    </button>
    <button
        type="button"
        wire:click="moveSelectedImageDown"
        wire:loading.attr="disabled"
        wire:target="moveSelectedImageDown"
        @disabled(! $this->canMoveSelectedImageDown())
        class="inline-flex items-center justify-center rounded-md border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 px-3 py-1.5 text-sm font-medium text-gray-900 dark:text-gray-100 hover:bg-gray-50 dark:hover:bg-gray-600 disabled:opacity-50 cursor-pointer disabled:cursor-not-allowed"
    >
        <span wire:loading.remove wire:target="moveSelectedImageDown">Move down</span>
        <span wire:loading wire:target="moveSelectedImageDown" class="text-xs">Saving…</span>
    </button>
@endif
<button
    type="button"
    wire:click="toggleSelectedImagePublishState"
    wire:loading.attr="disabled"
    wire:target="toggleSelectedImagePublishState"
    class="inline-flex items-center justify-center rounded-md border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 px-3 py-1.5 text-sm font-medium text-gray-900 dark:text-gray-100 hover:bg-gray-50 dark:hover:bg-gray-600 disabled:opacity-50 cursor-pointer disabled:cursor-not-allowed min-w-[7.5rem]"
>
    <span wire:loading.remove wire:target="toggleSelectedImagePublishState">{{ ucfirst($this->getSelectedImagePublishStateLabel()) }}</span>
    <span wire:loading wire:target="toggleSelectedImagePublishState" class="text-xs">Saving…</span>
</button>
