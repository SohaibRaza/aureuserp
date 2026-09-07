<x-filament-panels::page>
    <div class="space-y-6">
        {{-- Filters --}}
        {{ $this->form }}

        {{-- Report header --}}
        <x-filament::section>
            <x-slot name="heading">
                Sales by Customer — {{ \Carbon\Carbon::parse($data['date_from'])->format('d M Y') }} to
                {{ \Carbon\Carbon::parse($data['date_to'])->format('d M Y') }}
            </x-slot>

            @php
                $report = $this->salesByCustomerData;
                $rows = $report['rows'];
                $totals = $report['totals'];
            @endphp

            @if ($rows->isEmpty())
                <p class="py-6 text-center text-sm text-gray-500 dark:text-gray-400">
                    No orders in this period.
                </p>
            @else
                <div class="overflow-x-auto rounded-lg border border-gray-200 dark:border-white/5!">
                    <table class="min-w-full divide-y divide-gray-200 dark:divide-white/5!">
                        <thead class="bg-gray-50/50 dark:bg-white/5">
                            <tr>
                                <th scope="col" class="px-4 py-3 text-left text-xs font-medium tracking-wider text-gray-500 uppercase dark:text-gray-400">
                                    Customer
                                </th>
                                <th scope="col" class="px-4 py-3 text-right text-xs font-medium tracking-wider text-gray-500 uppercase dark:text-gray-400">
                                    Orders
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
                                        <a href="{{ \App\Filament\Pages\Reports\CustomerOrderLines::getUrl(['partner' => $row->partner_id, 'from' => $report['date_from'], 'to' => $report['date_to'], 'state' => $data['state']]) }}"
                                           class="text-primary-600 hover:underline dark:text-primary-400">
                                            {{ $row->customer }}
                                        </a>
                                    </td>
                                    <td class="px-4 py-3 text-right text-sm text-gray-600 dark:text-gray-400">
                                        {{ $row->orders }}
                                    </td>
                                    <td class="px-4 py-3 text-right text-sm text-gray-600 dark:text-gray-400">
                                        {{ number_format((float) $row->total_untaxed, 2) }}
                                    </td>
                                    <td class="px-4 py-3 text-right text-sm font-medium text-gray-900 dark:text-gray-100">
                                        {{ number_format((float) $row->total, 2) }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>

                        <tfoot class="border-t border-gray-200 bg-gray-50/50 dark:border-white/5! dark:bg-white/5">
                            <tr>
                                <td class="px-4 py-3 text-sm font-semibold text-gray-900 dark:text-gray-100">
                                    TOTAL
                                </td>
                                <td class="px-4 py-3 text-right text-sm font-semibold text-gray-900 dark:text-gray-100">
                                    {{ $totals['orders'] }}
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
