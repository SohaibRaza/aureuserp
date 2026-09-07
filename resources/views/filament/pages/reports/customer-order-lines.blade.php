<x-filament-panels::page>
    <div class="space-y-6">
        {{ $this->form }}

        <x-filament::section>
            <x-slot name="heading">
                {{ $this->customerName ?? 'Unknown customer' }} — order lines
            </x-slot>

            <x-slot name="description">
                {{ \Carbon\Carbon::parse($this->from)->format('d M Y') }} to
                {{ \Carbon\Carbon::parse($this->to)->format('d M Y') }}
                @if ($this->state !== 'all')
                    · {{ ucfirst($this->state) }} orders only
                @endif
            </x-slot>

            @php
                $report = $this->linesData;
                $kpis = $report['kpis'];
            @endphp

            {{-- KPI cards --}}
            <div class="grid grid-cols-2 gap-4 sm:grid-cols-4">
                <div class="rounded-lg border border-gray-200 p-4 dark:border-white/5!">
                    <p class="text-xs font-medium tracking-wider text-gray-500 uppercase dark:text-gray-400">Orders</p>
                    <p class="mt-1 text-2xl font-semibold text-gray-900 dark:text-gray-100">{{ $kpis['orders'] }}</p>
                </div>
                <div class="rounded-lg border border-gray-200 p-4 dark:border-white/5!">
                    <p class="text-xs font-medium tracking-wider text-gray-500 uppercase dark:text-gray-400">Units</p>
                    <p class="mt-1 text-2xl font-semibold text-gray-900 dark:text-gray-100">{{ $kpis['qty'] }}</p>
                </div>
                <div class="rounded-lg border border-gray-200 p-4 dark:border-white/5!">
                    <p class="text-xs font-medium tracking-wider text-gray-500 uppercase dark:text-gray-400">Untaxed</p>
                    <p class="mt-1 text-2xl font-semibold text-gray-900 dark:text-gray-100">{{ $kpis['untaxed'] }}</p>
                </div>
                <div class="rounded-lg border border-gray-200 p-4 dark:border-white/5!">
                    <p class="text-xs font-medium tracking-wider text-gray-500 uppercase dark:text-gray-400">Total</p>
                    <p class="mt-1 text-2xl font-semibold text-gray-900 dark:text-gray-100">{{ $kpis['total'] }}</p>
                </div>
            </div>
        </x-filament::section>

        @forelse ($report['orders'] as $order)
            <x-filament::section>
                <x-slot name="heading">
                    {{ $order['header']['name'] }}
                </x-slot>
                <x-slot name="description">
                    {{ \Carbon\Carbon::parse($order['header']['date'])->format('d M Y') }}
                    · <span class="fi-badge fi-badge-size-md fi-color-{{ $order['header']['state'] === 'sale' ? 'success' : 'gray' }}">
                        {{ ucfirst($order['header']['state']) }}
                    </span>
                    · Untaxed {{ number_format($order['header']['untaxed'], 2) }} · Total {{ number_format($order['header']['total'], 2) }}
                </x-slot>

                <div class="overflow-x-auto rounded-lg border border-gray-200 dark:border-white/5!">
                    <table class="min-w-full divide-y divide-gray-200 dark:divide-white/5!">
                        <thead class="bg-gray-50/50 dark:bg-white/5">
                            <tr>
                                <th class="px-4 py-2 text-left text-xs font-medium tracking-wider text-gray-500 uppercase dark:text-gray-400">Product / Description</th>
                                <th class="px-4 py-2 text-right text-xs font-medium tracking-wider text-gray-500 uppercase dark:text-gray-400">Qty</th>
                                <th class="px-4 py-2 text-right text-xs font-medium tracking-wider text-gray-500 uppercase dark:text-gray-400">Delivered</th>
                                <th class="px-4 py-2 text-right text-xs font-medium tracking-wider text-gray-500 uppercase dark:text-gray-400">Invoiced</th>
                                <th class="px-4 py-2 text-right text-xs font-medium tracking-wider text-gray-500 uppercase dark:text-gray-400">Unit</th>
                                <th class="px-4 py-2 text-right text-xs font-medium tracking-wider text-gray-500 uppercase dark:text-gray-400">Subtotal</th>
                                <th class="px-4 py-2 text-right text-xs font-medium tracking-wider text-gray-500 uppercase dark:text-gray-400">Total</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 dark:divide-white/5!">
                            @foreach ($order['lines'] as $line)
                                <tr class="hover:bg-gray-50/50 dark:hover:bg-white/5">
                                    <td class="px-4 py-2 text-sm text-gray-900 dark:text-gray-100">
                                        {{ $line->product_name }}
                                    </td>
                                    <td class="px-4 py-2 text-right text-sm text-gray-600 dark:text-gray-400">{{ (float) $line->product_qty }}</td>
                                    <td class="px-4 py-2 text-right text-sm text-gray-600 dark:text-gray-400">{{ (float) $line->qty_delivered }}</td>
                                    <td class="px-4 py-2 text-right text-sm text-gray-600 dark:text-gray-400">{{ (float) $line->qty_invoiced }}</td>
                                    <td class="px-4 py-2 text-right text-sm text-gray-600 dark:text-gray-400">{{ number_format((float) $line->price_unit, 2) }}</td>
                                    <td class="px-4 py-2 text-right text-sm text-gray-600 dark:text-gray-400">{{ number_format((float) $line->price_subtotal, 2) }}</td>
                                    <td class="px-4 py-2 text-right text-sm font-medium text-gray-900 dark:text-gray-100">{{ number_format((float) $line->price_total, 2) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </x-filament::section>
        @empty
            <p class="py-6 text-center text-sm text-gray-500 dark:text-gray-400">No order lines in this period.</p>
        @endforelse
    </div>
</x-filament-panels::page>
