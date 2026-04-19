{{--
    Phase 5 Plan 04b — CSV preview partial used inside the Quarantine tab's
    Resolve-mapping modal. Renders the first 10 rows of a quarantined CSV so
    the operator can eyeball which column is SKU vs price.
--}}
@php($rows = $rows ?? [])

@if (empty($rows))
    <div class="rounded-md border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900 dark:border-amber-800 dark:bg-amber-900/30 dark:text-amber-200">
        Preview unavailable — the quarantined file could not be read. Pick column indexes manually below; indexes are 0-based.
    </div>
@else
    <div class="overflow-x-auto rounded-md border border-gray-200 dark:border-gray-700">
        <table class="min-w-full divide-y divide-gray-200 text-xs dark:divide-gray-700">
            <thead class="bg-gray-50 dark:bg-gray-800">
                <tr>
                    @foreach ((array) $rows[0] as $i => $cell)
                        <th class="px-2 py-1 text-left font-semibold text-gray-700 dark:text-gray-300">
                            [{{ $i }}] {{ (string) $cell }}
                        </th>
                    @endforeach
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                @foreach (array_slice($rows, 1) as $row)
                    <tr class="bg-white dark:bg-gray-900">
                        @foreach ((array) $row as $cell)
                            <td class="px-2 py-1 font-mono text-gray-800 dark:text-gray-200">
                                {{ (string) $cell }}
                            </td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif
