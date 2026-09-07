<x-filament-panels::page>
    <div class="space-y-6">
        {{-- Filters --}}
        {{ $this->form }}

        {{-- Report header --}}
        <x-filament::section>
            <x-slot name="heading">
                Sales by Product — {{ \Carbon\Carbon::parse($data['date_from'])->format('d M Y') }} to
                {{ \Carbon\Carbon::parse($data['date_to'])->format('d M Y') }}
            </x-slot>

            @php
                $report = $this->salesByProductData;
                $rows = $report['rows'];
                $totals = $report['totals'];
            @endphp

            @if ($rows->isEmpty())
                <p class="py-6 text-center text-sm text-gray-500 dark:text-gray-400">
                    No order lines in this period.
                </p>
            @else
                <div class="overflow-x-auto rounded-lg border border-gray-200 dark:border-white/5!">
                    <table class="min-w-full divide-y divide-gray-200 dark:divide-white/5!">
                        <thead class="bg-gray-50/50 dark:bg-white/5">
                            <tr>
                                <th scope="col" class="px-4 py-3 text-left text-xs font-medium tracking-wider text-gray-500 uppercase dark:text-gray-400">
                                    Product
                                </th>
                                <th scope="col" class="px-4 py-3 text-left text-xs font-medium tracking-wider text-gray-500 uppercase dark:text-gray-400">
                                    Category
                                </th>
                                <th scope="col" class="px-4 py-3 text-right text-xs font-medium tracking-wider text-gray-500 uppercase dark:text-gray-400">
                                    Orders
                                </th>
                                <th scope="col" class="px-4 py-3 text-right text-xs font-medium tracking-wider text-gray-500 uppercase dark:text-gray-400">
                                    Qty
                                </th>
                                <th scope="col" class="px-4 py-3 text-right text-xs font-medium tracking-wider text-gray-500 uppercase dark:text-gray-400">
                                    Untaxed
                                </th>
                                <th scope="col" class="px-4 py-3 text-right text-xs font-medium tracking-wider text-gray-500 uppercase dark:text-gray-400">
                                    Total
                                </th>
                            </tr>
                        </thead>

                        <tbody class="divide-y divide-gray-200 dark:divide-white/5!">
                            @foreach ($rows as $row)
                                <tr class="hover:bg-gray-50/50 dark:hover:bg-white/5">
                                    <td class="px-4 py-3 text-sm font-medium text-gray-900 dark:text-gray-100">
                                        {{ $row->product }}
                                    </td>
                                    <td class="px-4 py-3 text-sm text-gray-600 dark:text-gray-400">
                                        {{ $row->category ?? '—' }}
                                    </td>
                                    <td class="px-4 py-3 text-right text-sm text-gray-600 dark:text-gray-400">
                                        {{ $row->orders }}
                                    </td>
                                    <td class="px-4 py-3 text-right text-sm text-gray-600 dark:text-gray-400">
                                        {{ rtrim(rtrim(number_format((float) $row->qty, 2, '.', ''), '0'), '.') }}
                                    </td>
                                    <td class="px-4 py-3 text-right text-sm text-gray-600 dark:text-gray-400">
                                        {{ number_format((float) $row->untaxed, 2) }}
                                    </td>
                                    <td class="px-4 py-3 text-right text-sm font-medium text-gray-900 dark:text-gray-100">
                                        {{ number_format((float) $row->total, 2) }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>

                        <tfoot class="border-t border-gray-200 bg-gray-50/50 dark:border-white/5! dark:bg-white/5">
                            <tr>
                                <td class="px-4 py-3 text-sm font-semibold text-gray-900 dark:text-gray-100" colspan="2">
                                    TOTAL
                                </td>
                                <td class="px-4 py-3 text-right text-sm font-semibold text-gray-900 dark:text-gray-100">
                                    {{ $totals['orders'] }}
                                </td>
                                <td class="px-4 py-3 text-right text-sm font-semibold text-gray-900 dark:text-gray-100">
                                    {{ $totals['qty'] }}
                                </td>
                                <td class="px-4 py-3 text-right text-sm font-semibold text-gray-900 dark:text-gray-100">
                                    {{ $totals['untaxed'] }}
                                </td>
                                <td class="px-4 py-3 text-right text-sm font-semibold text-gray-900 dark:text-gray-100">
                                    {{ $totals['grand'] }}
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            @endif
        </x-filament::section>
    </div>
</x-filament-panels::page>
