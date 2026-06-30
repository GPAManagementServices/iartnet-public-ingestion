<div class="overflow-x-auto">
    <table class="min-w-full border-collapse text-xs leading-tight">
        <thead class="bg-gray-50 dark:bg-gray-900">
            <tr>
                <th scope="col" class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider border-b border-gray-200 dark:border-gray-700">Key</th>
                <th scope="col" class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider border-b border-gray-200 dark:border-gray-700">Value</th>
            </tr>
        </thead>
        <tbody class="bg-white dark:bg-gray-800">
            @foreach($filteredRows as $row)
                @php
                    $rowKey = $row['key'] ?? '';
                    $rowValue = (string) ($row['value'] ?? '');
                    $editing = $this->isRecordDetailValueRowEditing($rowKey);
                    $editable = $this->isRecordDetailValueRowEditable($rowKey);
                @endphp
                <tr
                    wire:key="card-detail-row-{{ md5($this->cardDetailActiveTab.'|'.$rowKey) }}"
                    class="border-b border-gray-200 dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-700/50 transition-colors"
                >
                    <td class="whitespace-nowrap py-2 px-4 font-mono {{ $this->getKeyCellClasses($rowKey) }}">{{ $rowKey }}</td>
                    <td class="py-2 px-4 text-gray-700 dark:text-gray-300 break-words align-top">
                        @if($editing)
                            <div class="flex flex-col gap-2">
                                <x-filament::input.wrapper>
                                    {{-- textarea: un input text normalizza / perde i ritorni a capo; text_value può contenere newline --}}
                                    <textarea
                                        wire:model="recordDetailEditingDraftValue"
                                        rows="8"
                                        autocomplete="off"
                                        class="fi-input block w-full resize-y rounded-lg px-3 py-2 text-sm text-gray-950 outline-none transition duration-75 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-primary-600 disabled:text-gray-500 dark:bg-white/5 dark:text-white dark:placeholder:text-gray-500 dark:focus:ring-primary-500 dark:disabled:text-gray-400"
                                    ></textarea>
                                </x-filament::input.wrapper>
                                <div class="flex flex-wrap items-center gap-2">
                                    <x-filament::button
                                        type="button"
                                        wire:click="saveEditRecordDetailRow"
                                        color="primary"
                                        size="sm"
                                        wire:loading.attr="disabled"
                                        wire:target="saveEditRecordDetailRow"
                                    >
                                        <span wire:loading.remove wire:target="saveEditRecordDetailRow">Save</span>
                                        <span wire:loading wire:target="saveEditRecordDetailRow">Saving…</span>
                                    </x-filament::button>
                                    <x-filament::button
                                        type="button"
                                        wire:click="cancelEditRecordDetailRow"
                                        color="gray"
                                        size="sm"
                                        outlined
                                        wire:loading.attr="disabled"
                                        wire:target="saveEditRecordDetailRow"
                                    >
                                        Cancel
                                    </x-filament::button>
                                </div>
                            </div>
                        @else
                            <div class="flex flex-wrap items-start justify-between gap-2">
                                <span class="min-w-0 flex-1 whitespace-pre-wrap break-words">{{ $rowValue }}</span>
                                @if($editable)
                                    <x-filament::button
                                        type="button"
                                        wire:click="startEditRecordDetailRow({{ \Illuminate\Support\Js::from($rowKey) }}, {{ \Illuminate\Support\Js::from($rowValue) }})"
                                        color="gray"
                                        size="xs"
                                        outlined
                                        class="shrink-0"
                                    >
                                        Edit
                                    </x-filament::button>
                                @endif
                            </div>
                        @endif
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
