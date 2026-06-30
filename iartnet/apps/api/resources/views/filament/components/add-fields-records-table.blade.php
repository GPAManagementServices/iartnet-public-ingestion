<div class="p-4">
    <p class="text-sm font-semibold text-gray-800 mb-4">
        Record Importati: {{ count($records) }}
    </p>
    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Record ID</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Field Name</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Value</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                @forelse ($records as $record)
                    <tr>
                        <td class="px-4 py-3 text-sm text-gray-900">{{ $record['record_id'] }}</td>
                        <td class="px-4 py-3 text-sm text-gray-900">{{ $record['field_name'] }}</td>
                        <td class="px-4 py-3 text-sm text-gray-900">{{ $record['value_text'] }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="3" class="px-4 py-3 text-sm text-gray-500 text-center">
                            Nessun record trovato
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
