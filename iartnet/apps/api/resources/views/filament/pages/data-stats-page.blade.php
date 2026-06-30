<x-filament-panels::page>
    <div class="rounded-lg border border-gray-200 bg-white p-6 dark:border-gray-700 dark:bg-gray-800">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                <thead>
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-600 dark:text-gray-300">
                            Institution
                        </th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-600 dark:text-gray-300">
                            Tot_Cards
                        </th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                    @forelse ($this->stats as $row)
                        <tr>
                            <td class="px-4 py-3 text-sm text-gray-900 dark:text-gray-100">
                                {{ $row['name'] ?? '' }}
                            </td>
                            <td class="px-4 py-3 text-sm font-medium text-gray-900 dark:text-gray-100">
                                {{ (int) ($row['tot_cards'] ?? 0) }}
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="2" class="px-4 py-6 text-sm text-gray-500 dark:text-gray-400">
                                Nessun dato disponibile.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</x-filament-panels::page>

