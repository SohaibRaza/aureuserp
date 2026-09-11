<x-filament-panels::page>
    @once
        <style>
            .recon-match-columns {
                display: grid;
                grid-template-columns: minmax(0, 1fr);
                gap: 1rem;
            }

            @media (min-width: 1024px) {
                .recon-match-columns {
                    grid-template-columns: repeat(2, minmax(0, 1fr));
                }
            }
        </style>
    @endonce

    @php
        $summary = $this->summary;
        $bankLines = $this->bankLines;
        $importBatch = $this->importBatch;
        $ledger = $this->ledgerCandidates;
        $proposals = $this->proposals;
        $confirmed = $this->confirmedMatches;
        $basket = $this->basketCheck;
        $locked = ! $summary['can_edit'];
        $financialMutationsEnabled = config('reconciliation.financial_mutations_enabled', false);
    @endphp

    <div class="space-y-6">
        {{-- Header: balances + difference + status actions --}}
        <div class="fi-section rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <div class="mb-3 flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 class="text-lg font-bold">{{ $record->name }} — {{ $record->journal?->name }}
                        <span class="ml-2 rounded-full bg-gray-100 px-2 py-0.5 text-xs font-semibold dark:bg-gray-800">{{ str($record->status)->replace('_', ' ')->title() }}</span>
                    </h2>
                    <p class="mt-1 text-sm text-gray-500">{{ $record->period_start?->format('d M Y') }} to {{ $record->period_end?->format('d M Y') }} · {{ $record->company?->name }} · {{ $record->currency?->code }}</p>
                </div>
                <div class="flex flex-wrap gap-2" aria-label="Reconciliation navigation">
                    <x-filament::button tag="a" :href="\App\Filament\Pages\Recon\ListReconciliations::getUrl()" color="gray" size="sm" icon="heroicon-o-arrow-left">All reconciliations</x-filament::button>
                    <x-filament::button tag="a" :href="\App\Filament\Pages\Recon\ReconReport::getUrl(['record' => $record->id])" color="gray" size="sm" icon="heroicon-o-document-chart-bar">View report</x-filament::button>
                </div>
            </div>

            <div class="grid grid-cols-2 gap-4 md:grid-cols-6">
                <div>
                    <div class="text-xs text-gray-500">Bank opening</div>
                    <div class="font-mono text-sm font-semibold">{{ number_format((float) $record->balance_start, 2) }}</div>
                </div>
                <div>
                    <div class="text-xs text-gray-500">Bank closing</div>
                    <div class="font-mono text-sm font-semibold">{{ number_format((float) $record->balance_end_real, 2) }}</div>
                </div>
                <div>
                    <div class="text-xs text-gray-500">Adjusted book</div>
                    <div class="font-mono text-sm font-semibold">{{ number_format((float) $summary['gl_balance_end'], 2) }}</div>
                </div>
                <div>
                    <div class="text-xs text-gray-500">Adjusted bank</div>
                    <div class="font-mono text-sm font-semibold">{{ number_format((float) $summary['adjusted_bank_balance'], 2) }}</div>
                </div>
                <div>
                    <div class="text-xs text-gray-500">Posted adjustments</div>
                    <div class="font-mono text-sm font-semibold">{{ number_format((float) $summary['adjustments_total'], 2) }}</div>
                </div>
                <div>
                    <div class="text-xs text-gray-500">Difference</div>
                    <div class="font-mono text-sm font-bold {{ abs($summary['difference']) < 0.005 ? 'text-green-600' : 'text-red-600' }}">
                        {{ number_format($summary['difference'], 2) }}
                    </div>
                </div>
            </div>

            <div class="mt-4 flex flex-wrap gap-2 border-t border-gray-100 pt-3 dark:border-white/10">
            @if (! $financialMutationsEnabled)
                    <div class="w-full rounded-lg bg-warning-50 p-3 text-sm text-warning-800 dark:bg-warning-500/10 dark:text-warning-400">
                        Financial actions are temporarily disabled while reconciliation controls are being upgraded. Statement import, matching, adjustments, completion, and reopening are unavailable until the rollout gate is enabled.
                    </div>
            @elseif ($record->status === 'completed')
                    <div class="w-full rounded-lg bg-warning-50 p-3 text-sm text-warning-800 dark:bg-warning-500/10 dark:text-warning-400">
                        <div class="font-medium">Reopening impact</div>
                        @if (empty($this->reopenImpact))
                            <div>No later reconciliation periods will be marked stale.</div>
                        @else
                            <div>{{ count($this->reopenImpact) }} later reconciliation period(s) will be marked stale and must be reviewed again:</div>
                            <ul class="mt-1 list-inside list-disc">
                                @foreach ($this->reopenImpact as $affected)
                                    <li>{{ $affected['name'] }} · {{ $affected['period_start'] }} to {{ $affected['period_end'] }} · {{ str($affected['status'])->replace('_', ' ')->title() }}</li>
                                @endforeach
                            </ul>
                        @endif
                    </div>
                    <label class="w-full max-w-md text-sm">
                        <span class="mb-1 block font-medium">Reason for reopening</span>
                        <x-filament::input.wrapper>
                            <x-filament::input wire:model="reopenReason" aria-label="Reason for reopening" />
                        </x-filament::input.wrapper>
                    </label>
                    @can('reopen', $record)
                        <x-filament::button wire:click="reopen" color="danger" icon="heroicon-o-arrow-path">
                            Request reopen
                        </x-filament::button>
                    @endcan
                @elseif ($record->status === 'reopen_requested')
                    <div class="w-full text-sm text-warning-700">Reopen requested: {{ $record->reopen_request_reason }}</div>
                    <div class="w-full rounded-lg bg-warning-50 p-3 text-sm text-warning-800 dark:bg-warning-500/10 dark:text-warning-400">
                        <div class="font-medium">Approval impact</div>
                        @if (empty($this->reopenImpact))
                            <div>No later reconciliation periods will be marked stale.</div>
                        @else
                            <div>Approving will mark {{ count($this->reopenImpact) }} later reconciliation period(s) stale:</div>
                            <ul class="mt-1 list-inside list-disc">
                                @foreach ($this->reopenImpact as $affected)
                                    <li>{{ $affected['name'] }} · {{ $affected['period_start'] }} to {{ $affected['period_end'] }} · {{ str($affected['status'])->replace('_', ' ')->title() }}</li>
                                @endforeach
                            </ul>
                        @endif
                    </div>
                    @can('reopen', $record)
                        <x-filament::button wire:click="approveReopen" color="danger" icon="heroicon-o-check">
                            Approve reopen
                        </x-filament::button>
                    @endcan
                @elseif ($record->status === 'stale')
                    <div class="w-full rounded-lg bg-danger-50 p-3 text-sm text-danger-800 dark:bg-danger-500/10 dark:text-danger-300">
                        <div class="font-medium">This reconciliation is stale and cannot be edited.</div>
                        <div>{{ $record->stale_reason }}</div>
                    </div>
                    <label class="w-full max-w-md text-sm">
                        <span class="mb-1 block font-medium">Reason for starting rework</span>
                        <x-filament::input.wrapper>
                            <x-filament::input wire:model="reopenReason" aria-label="Reason for starting stale reconciliation rework" />
                        </x-filament::input.wrapper>
                    </label>
                    @can('reopen', $record)
                        <x-filament::button wire:click="reworkStale" color="danger" icon="heroicon-o-arrow-path">
                            Start controlled rework
                        </x-filament::button>
                    @endcan
                @elseif (in_array($record->status, \App\Services\Recon\ReconciliationMutationGuard::EDITABLE_STATUSES, true))
                    @can('review', $record)
                        <x-filament::button wire:click="startReview" color="info" icon="heroicon-o-eye">
                            Start review
                        </x-filament::button>
                    @endcan
                @else
                    @if ($record->status === 'review')
                        @can('review', $record)
                            <x-filament::button wire:click="backToInProgress" color="gray" icon="heroicon-o-arrow-uturn-left">
                                Back to in progress
                            </x-filament::button>
                        @endcan
                        @can('complete', $record)
                            <x-filament::button wire:click="complete" color="success" icon="heroicon-o-check-circle">
                                Complete
                            </x-filament::button>
                        @endcan
                    @endif
                @endif
            </div>

            @if (! empty($summary['check']['issues']))
                <div class="fi-notification mt-3 rounded-lg bg-warning-50 p-3 text-sm text-warning-800 dark:bg-warning-500/10 dark:text-warning-400">
                    <strong>Completion blockers:</strong>
                    <ul class="ml-4 list-disc">
                        @foreach ($summary['check']['issues'] as $issue)
                            <li>{{ $issue }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            @if ($locked)
                <div class="mt-3 rounded-lg border border-gray-200 bg-gray-50 p-3 text-sm text-gray-700 dark:border-white/10 dark:bg-white/5 dark:text-gray-300" role="status">
                    <div class="font-medium">This reconciliation is read-only.</div>
                    <p class="mt-1">{{ $record->status === 'review' ? 'Review is in progress; return it to preparation before changing evidence.' : 'Completed evidence cannot be edited. Use Request reopen to begin the controlled impact review.' }}</p>
                </div>
            @endif
        </div>

        {{-- Import, or verify that retrying the exact same source is idempotent. --}}
        @if (! $locked)
            <div class="fi-section rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                <details {{ $bankLines->isEmpty() ? 'open' : '' }}>
                    <summary class="cursor-pointer list-none font-semibold">
                        <span>{{ $bankLines->isEmpty() ? 'Import bank statement' : 'Verify duplicate statement' }}</span>
                        <span class="ml-2 text-sm font-normal text-gray-500">{{ $bankLines->isEmpty() ? 'Preview and validate a CSV before accepting it' : 'Retry only the exact same file; a different statement requires a new reconciliation' }}</span>
                    </summary>
                <div class="max-w-3xl">
                    <div class="mb-4 rounded-lg bg-primary-50 p-3 text-sm text-primary-900 dark:bg-primary-950/30 dark:text-primary-100">
                        <div class="font-medium">Importing into</div>
                        <div>{{ $record->company?->name }} · {{ $record->bankAccount?->account_number }} · {{ $record->journal?->name }} · {{ $record->currency?->code }}</div>
                        <div class="mt-1 text-xs">Reconciliation period: {{ $record->period_start?->format('d M Y') }} to {{ $record->period_end?->format('d M Y') }}</div>
                    </div>
                    {{ $this->importForm }}
                    @if ($importPreview)
                        <div class="mt-3 rounded-lg bg-gray-50 p-3 text-sm dark:bg-white/5">
                            <div class="font-medium">Validation preview{{ isset($importPreview['filename']) ? ': '.$importPreview['filename'] : '' }}</div>
                            @if (! empty($importPreview['errors']))
                                <div class="mt-2 rounded-md bg-danger-50 p-2 text-danger-700 dark:bg-danger-950/30 dark:text-danger-300">
                                    <ul class="ml-4 list-disc">
                                        @foreach ($importPreview['errors'] as $error)
                                            <li>{{ $error }}</li>
                                        @endforeach
                                    </ul>
                                </div>
                            @else
                                <div class="mt-2 grid grid-cols-2 gap-2 sm:grid-cols-4">
                                    <div><span class="text-gray-500">Rows</span><div class="font-mono">{{ $importPreview['count'] }}</div></div>
                                    <div><span class="text-gray-500">Debits</span><div class="font-mono">{{ $importPreview['debit_total'] }}</div></div>
                                    <div><span class="text-gray-500">Credits</span><div class="font-mono">{{ $importPreview['credit_total'] }}</div></div>
                                    <div><span class="text-gray-500">Net</span><div class="font-mono">{{ $importPreview['net_total'] }}</div></div>
                                    <div><span class="text-gray-500">Calculated closing</span><div class="font-mono">{{ $importPreview['calculated_closing'] }}</div></div>
                                    <div><span class="text-gray-500">Declared closing</span><div class="font-mono">{{ $importPreview['declared_closing'] }}</div></div>
                                    <div><span class="text-gray-500">Encoding</span><div>{{ $importPreview['encoding'] }}</div></div>
                                    <div><span class="text-gray-500">Delimiter</span><div>{{ $importPreview['delimiter'] }}</div></div>
                                </div>
                                <div class="mt-2 text-xs text-gray-600 dark:text-gray-400">
                                    Mapping:
                                    @foreach ($importPreview['mapping'] as $field => $heading)
                                        <span class="mr-2">{{ str($field)->replace('_', ' ')->title() }} → {{ $heading }}</span>
                                    @endforeach
                                </div>
                                <div class="mt-2 max-h-40 overflow-y-auto font-mono text-xs">
                                    @foreach ($importPreview['rows'] as $row)
                                        <div>{{ $row['transaction_date'] }} · {{ $row['reference'] ?: 'No reference' }} · {{ $row['amount'] }}</div>
                                    @endforeach
                                </div>
                                @if ($importPreview['calculated_closing'] !== $importPreview['declared_closing'])
                                    <p class="mt-2 font-medium text-danger-600">Control failure: calculated and declared closing balances do not agree.</p>
                                @endif
                                @if ($importPreview['period_changed'])
                                    <p class="mt-2 font-medium text-warning-700">The statement period differs from the reconciliation period. The reconciliation period remains unchanged unless you explicitly enable period adoption and provide a reason.</p>
                                @endif
                            @endif
                        </div>
                    @endif
                    @if ($importResult)
                        <div class="mt-3 rounded-lg {{ $importResult['status'] === 'accepted' ? 'bg-success-50 text-success-800' : 'bg-warning-50 text-warning-800' }} p-3 text-sm dark:bg-white/5">
                            <div class="font-semibold">Import batch #{{ $importResult['id'] }} · {{ str($importResult['status'])->title() }}</div>
                            <div>{{ $importResult['filename'] }} · {{ $importResult['lines_imported'] }} accepted · {{ $importResult['lines_duplicate'] }} duplicates</div>
                            <div class="mt-1 break-all font-mono text-xs">SHA-256: {{ $importResult['content_hash'] }} · Parser: {{ $importResult['parser_version'] }}</div>
                            @if (! empty($importResult['errors']))
                                <p class="mt-2 font-medium">Correct these issues and retry:</p>
                                <ul class="ml-4 list-disc">
                                    @foreach ($importResult['errors'] as $error)
                                        <li>{{ $error }}</li>
                                    @endforeach
                                </ul>
                            @endif
                        </div>
                    @endif
                    <div class="mt-3 flex gap-2">
                        <x-filament::button wire:click="previewImport" color="gray" icon="heroicon-o-eye">
                            Preview
                        </x-filament::button>
                        <x-filament::button wire:click="importStatement" color="primary" icon="heroicon-o-arrow-down-tray">
                            Import statement
                        </x-filament::button>
                    </div>
                </div>
                </details>
            </div>
        @endif

        @if ($importBatch)
            <div class="fi-section rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                <div class="flex flex-wrap items-start justify-between gap-2">
                    <div>
                        <h3 class="font-semibold">Statement import evidence</h3>
                        <p class="text-sm text-gray-500">Batch #{{ $importBatch->id }} · {{ $importBatch->original_filename }}</p>
                    </div>
                    <span class="rounded-full px-2 py-1 text-xs font-medium {{ $importBatch->status === 'accepted' ? 'bg-success-100 text-success-800' : ($importBatch->status === 'quarantined' ? 'bg-warning-100 text-warning-800' : 'bg-gray-100 text-gray-700') }}">
                        {{ str($importBatch->status)->replace('_', ' ')->title() }}
                    </span>
                </div>
                <div class="mt-3 grid grid-cols-2 gap-3 text-sm md:grid-cols-4">
                    <div><span class="text-gray-500">Source</span><div>{{ str($importBatch->source_type)->replace('_', ' ')->title() }}</div></div>
                    <div><span class="text-gray-500">Account</span><div>{{ $importBatch->account_identifier }}</div></div>
                    <div><span class="text-gray-500">Currency</span><div>{{ $importBatch->currency_code }}</div></div>
                    <div><span class="text-gray-500">Parser</span><div>{{ $importBatch->parser_version }}</div></div>
                    <div><span class="text-gray-500">Statement period</span><div>{{ $importBatch->statement_start_date?->format('d M Y') }} to {{ $importBatch->statement_end_date?->format('d M Y') }}</div></div>
                    <div><span class="text-gray-500">Rows</span><div>{{ $importBatch->control_transaction_count ?? 0 }} accepted / {{ $importBatch->lines_duplicate ?? 0 }} duplicate</div></div>
                    <div><span class="text-gray-500">Debits / Credits</span><div class="font-mono">{{ $importBatch->control_debit_total }} / {{ $importBatch->control_credit_total }}</div></div>
                    <div><span class="text-gray-500">Content hash</span><div class="font-mono text-xs" title="{{ $importBatch->content_hash }}">{{ str($importBatch->content_hash)->limit(18) }}</div></div>
                </div>
                @if (! empty($importBatch->error_report))
                    <div class="mt-3 rounded-md bg-warning-50 p-3 text-sm text-warning-800 dark:bg-warning-500/10 dark:text-warning-300">
                        <div class="font-medium">Quarantine reasons</div>
                        <ul class="ml-4 mt-1 list-disc">
                            @foreach ($importBatch->error_report as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            </div>
        @endif

        {{-- Matching workspace --}}
        @if ($bankLines->isNotEmpty())
            <div class="recon-match-columns">
                {{-- Pane 1: statement lines --}}
                <div>
                    <h3 class="mb-2 font-semibold">Bank statement lines</h3>
                    <div class="mb-2 grid grid-cols-1 gap-2 sm:grid-cols-2">
                        <x-filament::input.wrapper>
                            <x-filament::input wire:model.live.debounce.300ms="bankSearch" aria-label="Search bank statement lines" placeholder="Search reference or party" />
                        </x-filament::input.wrapper>
                        <x-filament::input.wrapper>
                            <x-filament::input.select wire:model.live="bankStatus" aria-label="Filter bank statement lines">
                                <option value="all">All lines</option>
                                <option value="open">Unmatched</option>
                                <option value="reconciled">Reconciled</option>
                            </x-filament::input.select>
                        </x-filament::input.wrapper>
                    </div>
                    <div class="max-h-96 space-y-2 overflow-y-auto rounded-lg border border-gray-200 p-2 dark:border-white/10" aria-label="Bank statement lines">
                        @forelse ($bankLines as $line)
                            <div class="flex items-center justify-between rounded-md border p-2 text-sm {{ in_array($line->id, $bankSelection) ? 'border-primary-500 bg-primary-50 dark:bg-primary-500/10' : 'border-gray-200 dark:border-white/10' }} {{ $line->terminal_treatment ? 'opacity-50' : '' }}">
                                <div>
                                    <div class="font-medium">
                                        {{ $line->transaction_date?->format('d M') }} — {{ $line->partner_name ?: '—' }}
                                        @if ($line->is_duplicate)
                                            <span class="ml-1 rounded bg-warning-100 px-1 text-xs text-warning-700">dup</span>
                                        @endif
                                    </div>
                                    <div class="text-xs text-gray-500">{{ $line->payment_reference ?: 'No reference' }} · {{ $line->external_id ?: 'No external ID' }}</div>
                                    <div class="text-xs text-gray-400">Value date: {{ $line->value_date?->format('d M Y') ?: '—' }} · Source: {{ $line->transaction_code ?: '—' }}</div>
                                    @if ($line->terminal_treatment)
                                        <div class="text-xs font-medium text-green-600">{{ str($line->terminal_treatment)->replace('_', ' ')->title() }}</div>
                                    @endif
                                </div>
                                <div class="flex items-center gap-2">
                                    <span class="font-mono {{ $line->amount >= 0 ? 'text-green-600' : 'text-red-600' }}">{{ number_format((float) $line->amount, 2) }}</span>
                                    @if ($line->terminal_treatment)
                                        <span class="text-xs font-bold text-green-600">✓</span>
                                    @elseif (! $locked && $financialMutationsEnabled)
                                        <x-filament::button wire:click="toggleBank({{ $line->id }})" size="xs" color="gray" :aria-label="(in_array($line->id, $bankSelection) ? 'Remove' : 'Select').' bank line '.$line->id">{{ in_array($line->id, $bankSelection) ? 'selected' : 'select' }}</x-filament::button>
                                    @endif
                                </div>
                            </div>
                        @empty
                            <p class="p-4 text-sm text-gray-500">No statement lines. Import a statement above.</p>
                        @endforelse
                    </div>
                    <div class="mt-2">{{ $bankLines->onEachSide(1)->links() }}</div>
                </div>

                {{-- Pane 2: ledger candidates --}}
                <div>
                    <h3 class="mb-2 font-semibold">Ledger candidates ({{ $ledger->total() }})</h3>
                    <div class="mb-2">
                        <x-filament::input.wrapper>
                            <x-filament::input wire:model.live.debounce.300ms="ledgerSearch" aria-label="Search ledger candidates" placeholder="Search journal entry or reference" />
                        </x-filament::input.wrapper>
                    </div>
                    <div class="max-h-96 space-y-2 overflow-y-auto rounded-lg border border-gray-200 p-2 dark:border-white/10">
                        @forelse ($ledger as $l)
                            <div class="flex items-center justify-between rounded-md border p-2 text-sm {{ in_array($l->id, $ledgerSelection) ? 'border-primary-500 bg-primary-50 dark:bg-primary-500/10' : 'border-gray-200 dark:border-white/10' }}">
                                <div>
                                    <div class="font-medium">{{ $l->date ? \Carbon\Carbon::parse($l->date)->format('d M') : '—' }} — {{ $l->move_name }}</div>
                                    <div class="text-xs text-gray-500">{{ $l->name }} · {{ $l->account_type }}</div>
                                </div>
                                <div class="flex items-center gap-2">
                                    <span class="font-mono {{ ($l->balance ?? 0) >= 0 ? 'text-green-600' : 'text-red-600' }}">{{ number_format((float) ($l->balance ?? 0), 2) }}</span>
                                    @if (! $locked && $financialMutationsEnabled)
                                        <x-filament::button wire:click="toggleLedger({{ $l->id }})" size="xs" color="gray" :aria-label="(in_array($l->id, $ledgerSelection) ? 'Remove' : 'Select').' ledger line '.$l->id">{{ in_array($l->id, $ledgerSelection) ? 'selected' : 'select' }}</x-filament::button>
                                    @endif
                                </div>
                            </div>
                        @empty
                            <div class="p-4 text-sm text-gray-500">
                                <p class="font-medium">No open ledger candidates.</p>
                                <p class="mt-1">Ledger candidates are posted, unreconciled move lines on this reconciliation’s exact company, bank account, currency, and statement-date cutoff.</p>
                                <p class="mt-1">Create and post an incoming or outgoing payment using the <span class="font-medium">Bank Transactions</span> journal, then return here. Imported bank rows do not create ledger entries.</p>
                            </div>
                        @endforelse
                    </div>
                    <div class="mt-2">{{ $ledger->onEachSide(1)->links() }}</div>
                </div>
            </div>

            {{-- Basket --}}
            @if (! empty($bankSelection) || ! empty($ledgerSelection))
                <div class="rounded-lg border border-primary-200 bg-primary-50 p-3 text-sm dark:border-primary-500/30 dark:bg-primary-500/10" role="status" aria-live="polite">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <span><strong>{{ count($bankSelection) }}</strong> bank line(s) and <strong>{{ count($ledgerSelection) }}</strong> ledger line(s) selected. Review allocations below before confirming.</span>
                        @if (! $locked && $financialMutationsEnabled)
                            <x-filament::button wire:click="clearSelection" size="xs" color="gray">Clear selection</x-filament::button>
                        @endif
                    </div>
                </div>
            @endif
            @if (! $locked && $financialMutationsEnabled)
                <div class="fi-section rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                    <h3 class="mb-2 font-semibold">
                        Match basket — bank {{ number_format($basket['bank_total'], 2) }} vs ledger {{ number_format($basket['ledger_total'], 2) }}
                        <span class="{{ $basket['balanced'] ? 'text-green-600' : 'text-red-600' }}">
                            {{ $basket['balanced'] ? '(balanced)' : '(variance '.number_format($basket['variance'], 2).')' }}
                        </span>
                    </h3>
                    @if ($basket['error'] ?? null)
                        <p class="mb-2 text-sm text-danger-600">{{ $basket['error'] }}</p>
                    @endif
                    <div class="mb-3 grid grid-cols-1 gap-2 md:grid-cols-2">
                        @foreach ($bankSelection as $lineId)
                            <label class="text-xs">Bank line #{{ $lineId }} allocation
                                <x-filament::input.wrapper>
                                    <x-filament::input type="number" step="0.0001" wire:model.live.debounce.300ms="allocations.bank.{{ $lineId }}" />
                                </x-filament::input.wrapper>
                            </label>
                        @endforeach
                        @foreach ($ledgerSelection as $lineId)
                            <label class="text-xs">Ledger line #{{ $lineId }} allocation
                                <x-filament::input.wrapper>
                                    <x-filament::input type="number" step="0.0001" wire:model.live.debounce.300ms="allocations.ledger.{{ $lineId }}" />
                                </x-filament::input.wrapper>
                            </label>
                        @endforeach
                    </div>
                    <div class="flex gap-2">
                        <x-filament::button wire:click="runAutoMatch" color="gray" icon="heroicon-o-bolt">
                            Find suggested matches
                        </x-filament::button>
                        <x-filament::button wire:click="confirmBasket" color="success" icon="heroicon-o-check">
                            Confirm match
                        </x-filament::button>
                    </div>
                </div>
            @endif

            @if ($confirmed->isNotEmpty())
                <div class="fi-section rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                        <h3 class="mb-2 font-semibold">Confirmed matches ({{ $confirmed->count() }})</h3>
                        <div class="space-y-2">
                            @foreach ($confirmed as $match)
                            @php
                                $matchedBankLines = $match->bankLines;
                                $matchedLedgerLines = $match->ledgerLines;
                            @endphp
                            <div class="rounded-md border border-gray-200 p-3 text-sm dark:border-white/10">
                                <span class="flex flex-wrap items-center gap-1.5">
                                    {{ $match->notes ?: 'Manual match' }} · {{ number_format((float) $match->amount, 2) }}
                                    @if ($match->accounting_mode === 'ledger_group')
                                        <span class="rounded bg-info-100 px-1.5 py-0.5 text-xs font-medium text-info-700 dark:bg-info-500/10 dark:text-info-400" title="Ledger lines were reconciled through the accounting engine">GL reconciled</span>
                                    @else
                                        <span class="rounded bg-gray-100 px-1.5 py-0.5 text-xs font-medium text-gray-600 dark:bg-white/10 dark:text-gray-300" title="Tracked by reconciliation only; no accounting partial reconciles were created">Allocation only</span>
                                    @endif
                                    @if (! empty($match->accounting_partial_ids))
                                        <span class="rounded bg-success-100 px-1.5 py-0.5 font-mono text-xs font-medium text-success-700 dark:bg-success-500/10 dark:text-success-400" title="Accounting partial reconciles created by this match">P{{ implode(', P', $match->accounting_partial_ids) }}</span>
                                    @endif
                                </span>
                                <div class="mt-3 grid gap-3 md:grid-cols-2">
                                    <div class="rounded border border-gray-100 bg-gray-50 p-2 dark:border-white/10 dark:bg-white/5">
                                        <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">Bank statement line(s)</div>
                                        @foreach ($matchedBankLines as $matchedLine)
                                            @php $bankLine = $matchedLine->resolve(); @endphp
                                            <div class="mt-1 border-t border-gray-200 pt-1 dark:border-white/10">
                                                <div class="font-medium">#{{ $matchedLine->lineable_id }} · {{ $bankLine?->transaction_date?->format('d M Y') ?: 'No date' }}</div>
                                                <div class="text-xs text-gray-500">Reference: {{ $bankLine?->payment_reference ?: 'None' }} · External ID: {{ $bankLine?->external_id ?: 'None' }}</div>
                                                <div class="font-mono text-xs">Applied: {{ number_format((float) $matchedLine->amount_applied, 4) }}</div>
                                            </div>
                                        @endforeach
                                    </div>
                                    <div class="rounded border border-gray-100 bg-gray-50 p-2 dark:border-white/10 dark:bg-white/5">
                                        <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">Ledger move line(s)</div>
                                        @foreach ($matchedLedgerLines as $matchedLine)
                                            @php
                                                $ledgerLine = $matchedLine->resolve();
                                                $ledgerMove = $ledgerLine?->move;
                                            @endphp
                                            <div class="mt-1 border-t border-gray-200 pt-1 dark:border-white/10">
                                                <div class="font-medium">#{{ $matchedLine->lineable_id }} · {{ $ledgerLine?->date ? \Carbon\Carbon::parse($ledgerLine->date)->format('d M Y') : 'No date' }}</div>
                                                <div class="text-xs text-gray-500">Move: {{ $ledgerMove?->name ?: 'Unknown' }} · Reference: {{ $ledgerMove?->reference ?: $ledgerLine?->name ?: 'None' }}</div>
                                                <div class="font-mono text-xs">Applied: {{ number_format((float) $matchedLine->amount_applied, 4) }}</div>
                                            </div>
                                        @endforeach
                                    </div>
                                </div>
                                <div class="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-xs text-gray-500">
                                    <span>Mode: <strong>{{ str((string) $match->accounting_mode)->replace('_', ' ')->title() }}</strong></span>
                                    <span>Applied: <strong class="font-mono">{{ number_format((float) $match->accounting_applied_amount, 4) }}</strong></span>
                                    <span>Confirmed: <strong>{{ $match->confirmed_at?->format('d M Y H:i') ?: 'Unknown' }}</strong></span>
                                    @if ($match->confirmed_by)
                                        <span>By user #{{ $match->confirmed_by }}</span>
                                    @endif
                                    @if ($match->accounting_applied_at)
                                        <span>Accounting recorded: <strong>{{ $match->accounting_applied_at->format('d M Y H:i') }}</strong> by user #{{ $match->accounting_applied_by ?: 'unknown' }}</span>
                                    @endif
                                    @if (! empty($match->accounting_full_ids))
                                        <span>Full reconcile IDs: {{ implode(', ', $match->accounting_full_ids) }}</span>
                                    @endif
                                </div>
                                @if (! $locked && $financialMutationsEnabled)
                                    <div class="mt-3 grid gap-2 md:grid-cols-[minmax(0,1fr)_auto] md:items-end">
                                    <label class="text-xs font-medium text-gray-700 dark:text-gray-200">
                                        Reason for reversal
                                        <x-filament::input.wrapper class="mt-1">
                                            <x-filament::input
                                                type="text"
                                                wire:model="unmatchReasons.{{ $match->id }}"
                                                placeholder="Explain why this match must be reversed"
                                            />
                                        </x-filament::input.wrapper>
                                    </label>
                                    <x-filament::button wire:click="unmatchConfirmed({{ $match->id }})" size="xs" color="danger">Reverse match</x-filament::button>
                                    </div>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

            {{-- Proposals --}}
            @if ($proposals->isNotEmpty())
                <div class="fi-section rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                    <h3 class="mb-2 font-semibold">Proposed matches ({{ $proposals->count() }})</h3>
                    <div class="space-y-2">
                        @foreach ($proposals as $p)
                            <div class="flex items-center justify-between rounded-md border border-gray-200 p-2 dark:border-white/10">
                                <span class="text-sm">{{ $p->notes }} · {{ number_format((float) $p->amount, 2) }}</span>
                                @if (! $locked)
                                    <div class="flex gap-2">
                                        @if ($financialMutationsEnabled)
                                            <x-filament::button wire:click="confirmMatch({{ $p->id }})" size="xs" color="success">Confirm</x-filament::button>
                                        @endif
                                        <x-filament::button wire:click="rejectMatch({{ $p->id }})" size="xs" color="danger">Reject</x-filament::button>
                                    </div>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

            {{-- Adjustments + audit --}}
            <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
                <div class="fi-section rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                    <h3 class="mb-1 font-semibold">Adjustments (posted to GL)</h3>
                    <p class="mb-3 text-xs text-gray-500">Every adjustment must identify its counterpart account and, where applicable, the bank line it resolves. Posting uses the accounting workflow.</p>
                    @if (! $locked && $financialMutationsEnabled)
                        <div class="mb-3 max-w-xl">
                            {{ $this->adjustmentForm }}
                            <div class="mt-2">
                                <x-filament::button wire:click="postAdjustment" color="warning" icon="heroicon-o-bolt">Post adjustment</x-filament::button>
                            </div>
                        </div>
                        <details class="mb-3 max-w-xl rounded-lg border border-gray-200 p-3 dark:border-white/10">
                            <summary class="cursor-pointer text-sm font-medium">Dedicated transfer workflow</summary>
                            <p class="mt-2 text-xs text-gray-500">Use this for a bank-to-bank or clearing transfer. The destination account and selected statement line are validated by the accounting service.</p>
                            <div class="mt-3">{{ $this->transferForm }}</div>
                            <div class="mt-2"><x-filament::button wire:click="postTransferAdjustment" color="warning" size="sm" icon="heroicon-o-arrows-right-left">Post transfer</x-filament::button></div>
                        </details>
                        <details class="mb-3 max-w-xl rounded-lg border border-gray-200 p-3 dark:border-white/10">
                            <summary class="cursor-pointer text-sm font-medium">Dedicated FX difference workflow</summary>
                            <p class="mt-2 text-xs text-gray-500">Positive amounts use the gain account; negative amounts use the loss account. Both accounts are company-scoped and type-checked.</p>
                            <div class="mt-3">{{ $this->fxForm }}</div>
                            <div class="mt-2"><x-filament::button wire:click="postFxAdjustment" color="warning" size="sm" icon="heroicon-o-currency-dollar">Post FX difference</x-filament::button></div>
                        </details>
                        <details class="mb-3 max-w-xl rounded-lg border border-gray-200 p-3 dark:border-white/10">
                            <summary class="cursor-pointer text-sm font-medium">Dedicated returned-payment workflow</summary>
                            <p class="mt-2 text-xs text-gray-500">This reverses the existing posted payment through the accounting workflow and reopens the receivable/payable when valid.</p>
                            <div class="mt-3">{{ $this->returnedPaymentForm }}</div>
                            <div class="mt-2"><x-filament::button wire:click="postReturnedPaymentAdjustment" color="warning" size="sm" icon="heroicon-o-arrow-uturn-left">Post returned payment</x-filament::button></div>
                        </details>
                    @endif
                    <ul class="space-y-1 text-sm">
                        @foreach ($record->adjustments()->get() as $adj)
                            <li class="flex justify-between gap-3 border-b border-gray-100 py-1 text-sm dark:border-white/5">
                                <span>{{ \App\Models\Recon\ReconAdjustment::TYPES[$adj->type] ?? $adj->type }} — {{ $adj->notes ?: 'No note' }}<span class="block text-xs text-gray-500">{{ $adj->statement_line_id ? 'Linked bank line #'.$adj->statement_line_id : 'No bank line link' }} · {{ $adj->posted_at?->format('d M Y H:i') ?: 'Not posted' }}</span></span>
                                <span class="font-mono">{{ number_format((float) $adj->amount, 2) }}</span>
                            </li>
                        @endforeach
                    </ul>
                </div>

                <div class="fi-section rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                    <h3 class="mb-1 font-semibold">Exception queue and evidence</h3>
                    <p class="mb-3 text-xs text-gray-500">Classify timing differences and bank errors explicitly. Open exceptions block completion until they are resolved, carried forward, or approved by the appropriate workflow.</p>
                    @if (! $locked && $financialMutationsEnabled)
                        {{ $this->exceptionForm }}
                        <div class="mt-2">
                            <x-filament::button wire:click="classifyException" color="info" icon="heroicon-o-flag">Classify exception</x-filament::button>
                        </div>
                    @endif
                    <ul class="mt-3 space-y-1 text-sm">
                        @foreach ($record->reconcilingItems()->with('owner')->latest()->limit(25)->get() as $item)
                            <li class="rounded border border-gray-200 p-2 dark:border-white/10">
                                <div class="flex justify-between gap-3">
                                    <span>{{ str($item->classification->value)->replace('_', ' ')->title() }} · {{ $item->status->value }}</span>
                                    <span class="font-mono">{{ number_format((float) $item->amount, 2) }}</span>
                                </div>
                                <div class="mt-1 text-xs text-gray-500">Owner: {{ $item->owner?->name ?? 'Unassigned' }} · {{ $item->notes ?: 'No note' }} · {{ $item->statement_line_id ? 'Bank line #'.$item->statement_line_id : 'No source line' }}</div>
                                @if (! $locked && $financialMutationsEnabled && in_array($item->status->value, ['open', 'carried_forward']))
                                    <div class="mt-2 flex gap-2">
                                        <x-filament::input.wrapper class="flex-1">
                                            <x-filament::input wire:model="exceptionReasons.{{ $item->id }}" aria-label="Resolution reason for exception {{ $item->id }}" placeholder="Resolution or carry-forward reason" />
                                        </x-filament::input.wrapper>
                                        @if ($item->status->value === 'open' && $item->classification->value === 'duplicate')
                                            <x-filament::button wire:click="excludeDuplicate({{ $item->id }})" size="xs" color="warning">Exclude duplicate</x-filament::button>
                                        @elseif ($item->status->value === 'open' && $item->classification->value === 'bank_error')
                                            <x-filament::button wire:click="approveBankError({{ $item->id }})" size="xs" color="warning">Approve bank error</x-filament::button>
                                        @elseif ($item->status->value === 'open' && in_array($item->classification->value, ['outstanding_payment', 'deposit_in_transit']))
                                            <x-filament::button wire:click="carryForwardException({{ $item->id }})" size="xs" color="gray">Carry forward</x-filament::button>
                                        @elseif (in_array($item->classification->value, ['missing_book_entry', 'returned_payment', 'transfer', 'unidentified', 'disputed', 'investigation_required']))
                                            <x-filament::button wire:click="resolveCorrectedException({{ $item->id }})" size="xs" color="success">Mark corrected</x-filament::button>
                                        @endif
                                    </div>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </div>

                <div class="fi-section rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                    <h3 class="mb-2 font-semibold">Audit timeline</h3>
                    <ul class="max-h-72 space-y-1 overflow-y-auto text-sm">
                        @foreach ($record->events()->limit(50)->get()->reverse() as $ev)
                            <li class="flex justify-between border-b border-gray-100 py-1 dark:border-white/5">
                                <span class="font-medium">{{ $ev->event }}</span>
                                <span class="text-xs text-gray-500">{{ $ev->created_at?->format('d M H:i') }} · user #{{ $ev->actor_id }}</span>
                            </li>
                        @endforeach
                    </ul>
                </div>
            </div>
        @endif
    </div>
</x-filament-panels::page>
