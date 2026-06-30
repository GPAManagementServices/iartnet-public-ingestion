<div class="p-4 space-y-4">
    <div class="grid grid-cols-2 gap-4">
        <div class="p-4 bg-green-50 border border-green-200 rounded">
            <p class="text-sm font-semibold text-green-800">Record Importati</p>
            <p class="text-2xl font-bold text-green-600">{{ $imported }}</p>
        </div>
        <div class="p-4 bg-red-50 border border-red-200 rounded">
            <p class="text-sm font-semibold text-red-800">Record Saltati</p>
            <p class="text-2xl font-bold text-red-600">{{ $skipped }}</p>
        </div>
    </div>

    @if (!empty($errors))
        <div class="p-4 bg-yellow-50 border border-yellow-200 rounded">
            <p class="text-sm font-semibold text-yellow-800 mb-2">Record Non Inseriti:</p>
            <div class="max-h-64 overflow-y-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Riga</th>
                            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Chiave</th>
                            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Motivo</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        @foreach ($errors as $error)
                            <tr>
                                <td class="px-3 py-2 text-sm text-gray-900">{{ $error['row'] ?? '-' }}</td>
                                <td class="px-3 py-2 text-sm text-gray-900">{{ $error['key'] ?? '-' }}</td>
                                <td class="px-3 py-2 text-sm text-gray-900">{{ $error['reason'] ?? '-' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif
</div>
