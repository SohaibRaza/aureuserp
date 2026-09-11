<x-filament-panels::page>
    @php
        $r = $this->report;
    @endphp

    <div class="space-y-6">
        <x-filament::section>
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 class="text-lg font-semibold">{{ $record->name }} — {{ $record->journal?->name }}</h2>
                    <p class="mt-1 text-sm text-gray-500">{{ $record->period_start?->format('d M Y') }} to {{ $record->period_end?->format('d M Y') }} · {{ str($r['status'])->replace('_', ' ')->title() }}</p>
                </div>
                <div class="flex flex-wrap gap-2" aria-label="Report navigation">
                    <x-filament::button tag="a" :href="\App\Filament\Pages\Recon\ListReconciliations::getUrl()" color="gray" size="sm" icon="heroicon-o-arrow-left">All reconciliations</x-filament::button>
                    <x-filament::button tag="a" :href="\App\Filament\Pages\Recon\ReconcileWorkspace::getUrl(['record' => $record->id])" color="gray" size="sm" icon="heroicon-o-pencil-square">Open workspace</x-filament::button>
                </div>
            </div>

            @if ($r['snapshot_version'])
                <p class="mb-4 text-xs text-gray-500">Immutable completion snapshot v{{ $r['snapshot_version'] }} · {{ substr($r['snapshot_hash'], 0, 12) }}</p>
            @endif

            <div class="mt-4 grid grid-cols-2 gap-4 md:grid-cols-5">
                <div>
                    <div class="text-xs text-gray-500">Bank closing</div>
                    <div class="font-mono text-sm font-semibold">{{ number_format((float) $record->balance_end_real, 2) }}</div>
                </div>
                <div>
                    <div class="text-xs text-gray-500">Adjusted bank</div>
                    <div class="font-mono text-sm font-semibold">{{ number_format((float) $r['difference']['adjusted_bank_balance'], 2) }}</div>
                </div>
                <div>
                    <div class="text-xs text-gray-500">Adjusted book</div>
                    <div class="font-mono text-sm font-semibold">{{ number_format((float) $r['difference']['adjusted_book_balance'], 2) }}</div>
                </div>
                <div>
                    <div class="text-xs text-gray-500">Difference</div>
                    <div class="font-mono text-sm font-bold {{ abs($r['difference']['difference']) < 0.005 ? 'text-green-600' : 'text-red-600' }}">
                        {{ number_format($r['difference']['difference'], 2) }}
                    </div>
                </div>
                <div>
                    <div class="text-xs text-gray-500">Bank items</div>
                    <div class="font-mono text-sm font-semibold">{{ $r['reconciled']->count() }} / {{ $r['reconciled']->count() + $r['unreconciled']->count() }} reconciled</div>
                </div>
            </div>
            @if ($r['snapshot_version'])
                <div class="mt-4 rounded-lg border border-success-200 bg-success-50 p-3 text-sm text-success-800 dark:border-success-500/30 dark:bg-success-500/10 dark:text-success-200" role="status">
                    <div class="font-medium">Immutable completion evidence</div>
                    <p class="mt-1">This report reads snapshot v{{ $r['snapshot_version'] }} and remains reproducible even if the live ledger changes. Hash: <span class="font-mono">{{ substr($r['snapshot_hash'], 0, 16) }}…</span></p>
                </div>
            @else
                <div class="mt-4 rounded-lg border border-warning-200 bg-warning-50 p-3 text-sm text-warning-800 dark:border-warning-500/30 dark:bg-warning-500/10 dark:text-warning-200" role="note">
                    This is a live preparation report. It is not an immutable close record until the reconciliation is completed.
                </div>
            @endif
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">Reconciled bank items ({{ $r['reconciled']->count() }})</x-slot>
            <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <caption class="sr-only">Reconciled bank statement lines</caption>
                <thead><tr class="text-left text-xs text-gray-500 uppercase">
                    <th scope="col" class="py-2">Date</th><th scope="col">Partner</th><th scope="col">Reference / source</th><th scope="col" class="text-right">Amount</th>
                </tr></thead>
                <tbody>
                    @foreach ($r['reconciled'] as $l)
                        <tr class="border-t border-gray-100 dark:border-white/5">
                            <td class="py-1.5">{{ $l->transaction_date ? \Carbon\Carbon::parse($l->transaction_date)->format('d M') : '—' }}</td>
                            <td>{{ $l->partner_name }}</td>
                            <td>{{ $l->payment_reference ?: '—' }}<div class="text-xs text-gray-500">{{ ($l->external_id ?? null) ?: 'No external ID' }}</div></td>
                            <td class="text-right font-mono">{{ number_format((float) $l->amount, 2) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            </div>
        </x-filament::section>

        <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
        @if ($r['matches']->isNotEmpty())
            <x-filament::section>
                <x-slot name="heading">Confirmed matches ({{ $r['matches']->count() }})</x-slot>
                <div class="space-y-2">
                    @foreach ($r['matches'] as $match)
                        <div class="flex flex-wrap items-center justify-between gap-2 border-b border-gray-100 py-1.5 text-sm dark:border-white/5">
                            <span class="flex flex-wrap items-center gap-1.5">
                                <span class="font-medium">{{ $match->notes ?: 'Manual match' }}</span>
                                <span class="text-xs text-gray-500">{{ $match->confirmed_at?->format('d M Y H:i') }} · {{ number_format((float) $match->amount, 2) }}</span>
                                @if ($match->accounting_mode === 'ledger_group')
                                    <span class="rounded bg-info-100 px-1.5 py-0.5 text-xs font-medium text-info-700 dark:bg-info-500/10 dark:text-info-400" title="Ledger lines were reconciled through the accounting engine">GL reconciled</span>
                                @else
                                    <span class="rounded bg-gray-100 px-1.5 py-0.5 text-xs font-medium text-gray-600 dark:bg-white/10 dark:text-gray-300" title="Tracked by reconciliation only; no accounting partial reconciles were created">Allocation only</span>
                                @endif
                                @if (! empty($match->accounting_partial_ids))
                                    <span class="rounded bg-success-100 px-1.5 py-0.5 font-mono text-xs font-medium text-success-700 dark:bg-success-500/10 dark:text-success-400" title="Accounting partial reconciles created by this match">P{{ implode(', P', $match->accounting_partial_ids) }}</span>
                                @endif
                            </span>
                        </div>
                    @endforeach
                </div>
            </x-filament::section>
        @endif

        <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
            <x-filament::section>
                <x-slot name="heading">Unreconciled bank items ({{ $r['unreconciled']->count() }})</x-slot>
                @forelse ($r['unreconciled'] as $l)
                    <div class="flex justify-between gap-3 border-b border-gray-100 py-1 text-sm dark:border-white/5">
                        <span>{{ $l->transaction_date?->format('d M Y') }} · {{ $l->partner_name ?: '—' }} · {{ $l->payment_reference ?: 'No reference' }}<span class="block text-xs text-gray-500">{{ ($l->external_id ?? null) ?: 'No external ID' }}</span></span>
                        <span class="font-mono">{{ number_format((float) $l->amount, 2) }}</span>
                    </div>
                @empty
                    <p class="text-sm text-green-600">All bank items reconciled. 🎉</p>
                @endforelse
            </x-filament::section>

            <x-filament::section>
                <x-slot name="heading">Open reconciling items ({{ $r['reconciling_items']->count() }})</x-slot>
                @forelse ($r['reconciling_items'] as $item)
                    @php
                        $classification = $item->classification instanceof \BackedEnum ? $item->classification->value : $item->classification;
                        $status = $item->status instanceof \BackedEnum ? $item->status->value : $item->status;
                    @endphp
                    <div class="flex justify-between border-b border-gray-100 py-1 text-sm dark:border-white/5">
                        <span>{{ str($classification)->replace('_', ' ')->title() }} · {{ str($status)->replace('_', ' ')->title() }}<span class="block text-xs text-gray-500">Origin: {{ $item->origin_period_end ? \Carbon\Carbon::parse($item->origin_period_end)->format('d M Y') : 'Current period' }} · Owner: {{ $item->owner?->name ?? 'Unassigned' }}</span></span>
                        <span class="font-mono">{{ number_format((float) $item->amount, 2) }}</span>
                    </div>
                @empty
                    <p class="text-sm text-green-600">No open reconciling items.</p>
                @endforelse
            </x-filament::section>
        </div>
    </div>
</x-filament-panels::page>
