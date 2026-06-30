<div class="mt-6">
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 p-6">
        <div class="mb-4">
            <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Record Details</h3>
            <p class="text-sm text-gray-500 dark:text-gray-400">Record ID: {{ $component->selectedRecordId }}</p>
        </div>
        {{ $component->detailsTable }}
    </div>
</div>
