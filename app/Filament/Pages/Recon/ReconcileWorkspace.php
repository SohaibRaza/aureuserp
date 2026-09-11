<?php

namespace App\Filament\Pages\Recon;

use App\Enums\Recon\ReconcilingItemClassification;
use App\Enums\Recon\ReconcilingItemSide;
use App\Models\Recon\BankReconciliation;
use App\Models\Recon\ReconAdjustment;
use App\Models\Recon\ReconImportBatch;
use App\Models\Recon\StatementLine;
use App\Services\Recon\AdjustmentService;
use App\Services\Recon\CsvStatementParser;
use App\Services\Recon\FinalizationService;
use App\Services\Recon\MatchingEngine;
use App\Services\Recon\MatchService;
use App\Services\Recon\ReconciliationExceptionService;
use App\Services\Recon\ReconciliationLifecycleService;
use App\Services\Recon\ReconciliationMutationGuard;
use App\Services\Recon\StatementImporter;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Livewire\Attributes\Url;
use Livewire\WithPagination;
use Webkul\Account\Models\Account;
use Webkul\Account\Models\MoveLine;
use Webkul\Account\Models\Payment;
use Webkul\Security\Models\User;

class ReconcileWorkspace extends Page implements HasSchemas
{
    use HasPageShield, InteractsWithSchemas, WithPagination;

    protected string $view = 'filament.pages.recon.workspace';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-adjustments-horizontal';

    protected static ?string $slug = 'reconcile';

    protected static bool $shouldRegisterNavigation = false;

    protected static function getPagePermission(): ?string
    {
        return 'page_filament_reconcile_workspace';
    }

    public ?BankReconciliation $record = null;

    /** Basket state */
    public array $bankSelection = [];

    public array $ledgerSelection = [];

    #[Url]
    public ?string $tab = null;

    public ?array $data = [
        'type'                   => null,
        'amount'                 => null,
        'statement_line_id'      => null,
        'counterpart_account_id' => null,
        'notes'                  => null,
    ];

    public ?array $importData = [
        'statement_file'            => null,
        'statement_file_name'       => null,
        'csv'                       => null,
        'balance_start'             => null,
        'balance_end_real'          => null,
        'statement_start_date'      => null,
        'statement_end_date'        => null,
        'statement_date'            => null,
        'account_identifier'        => null,
        'currency_code'             => null,
        'control_debit_total'       => null,
        'control_credit_total'      => null,
        'control_transaction_count' => null,
        'adopt_statement_period'    => false,
        'period_change_reason'      => null,
    ];

    public string $reopenReason = '';

    #[Url]
    public string $bankSearch = '';

    #[Url]
    public string $bankStatus = 'all';

    #[Url]
    public string $ledgerSearch = '';

    public array $allocations = ['bank' => [], 'ledger' => []];

    public ?array $exceptionData = [
        'classification'    => null,
        'side'              => null,
        'statement_line_id' => null,
        'move_line_id'      => null,
        'amount'            => null,
        'owner_id'          => null,
        'evidence'          => null,
        'notes'             => null,
    ];

    public ?array $transferData = [
        'amount'                           => null,
        'destination_liquidity_account_id' => null,
        'statement_line_id'                => null,
        'notes'                            => null,
    ];

    public ?array $fxData = [
        'amount'            => null,
        'gain_account_id'   => null,
        'loss_account_id'   => null,
        'statement_line_id' => null,
        'notes'             => null,
    ];

    public ?array $returnedPaymentData = [
        'payment_id'        => null,
        'statement_line_id' => null,
        'reason'            => null,
    ];

    public array $exceptionReasons = [];

    /** Required audit explanation keyed by confirmed match ID. */
    public array $unmatchReasons = [];

    public ?array $importPreview = null;

    public ?array $importResult = null;

    public function mount(): void
    {
        $id = request()->query('record');
        $this->record = BankReconciliation::query()
            ->where('company_id', current_company_id())
            ->findOrFail($id);
        Gate::authorize('view', $this->record);

        $this->importForm->fill([
            'statement_start_date' => $this->record->period_start?->toDateString(),
            'statement_end_date'   => $this->record->period_end?->toDateString(),
            'statement_date'       => $this->record->period_end?->toDateString(),
            'account_identifier'   => $this->record->bankAccount?->account_number,
            'currency_code'        => $this->record->currency?->code,
        ]);
    }

    public function getTitle(): string
    {
        return 'Reconcile — '.$this->record?->name;
    }

    /* ------------------------------------------------------------------
     |  Adjustment form
     | ----------------------------------------------------------------- */

    public function adjustmentForm(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('type')
                ->id('recon-adjustment-type')
                ->label('Adjustment type')
                ->options(array_intersect_key(ReconAdjustment::TYPES, array_flip(AdjustmentService::POSTABLE_TYPES)))
                ->required()
                ->live(),
            TextInput::make('amount')
                ->id('recon-adjustment-amount')
                ->label('Amount (+ into bank / − out of bank)')
                ->numeric()
                ->required(),
            Select::make('statement_line_id')
                ->id('recon-adjustment-statement-line')
                ->label('Statement line resolved by this entry')
                ->options(fn () => StatementLine::query()
                    ->where('statement_id', $this->record->statement_id)
                    ->where('company_id', $this->record->company_id)
                    ->where('journal_id', $this->record->journal_id)
                    ->where('import_status', 'accepted')
                    ->whereNull('terminal_treatment')
                    ->orderBy('transaction_date')
                    ->get()
                    ->mapWithKeys(fn (StatementLine $line): array => [
                        $line->id => $line->transaction_date?->format('d M').' · '.($line->payment_reference ?: 'No reference').' · '.$line->amount,
                    ]))
                ->searchable()
                ->required(fn ($get): bool => ($get('type') ?? null) !== 'correction'),
            Select::make('counterpart_account_id')
                ->id('recon-adjustment-counterpart-account')
                ->label('Counterpart account')
                ->options(fn () => Account::query()->withoutGlobalScopes()->where(owned_by_company(current_company_id()))->where('deprecated', false)->orderBy('code')->get()->mapWithKeys(fn ($a) => [$a->id => $a->code.' — '.$a->name]))
                ->searchable()
                ->required(),
            Textarea::make('notes')->id('recon-adjustment-notes')->label('Notes')->rows(2),
        ])->statePath('data');
    }

    public function importForm(Schema $schema): Schema
    {
        return $schema->components([
            FileUpload::make('statement_file')->id('recon-import-statement-file')
                ->label('Bank statement CSV')
                ->disk('local')
                ->directory('reconciliation-imports')
                ->storeFileNamesIn('statement_file_name')
                ->visibility('private')
                ->acceptedFileTypes(['text/csv', 'text/plain', 'application/csv', 'application/vnd.ms-excel'])
                ->maxSize(10240)
                ->helperText('Upload a UTF-8 CSV up to 10 MB. Headings are mapped automatically and shown before import.'),
            Textarea::make('csv')->id('recon-import-csv')
                ->label('Or paste CSV')
                ->rows(5)
                ->placeholder("Transaction Date,Value Date,Amount,Reference,External ID,Account Number,Currency\n2026-09-01,2026-09-01,-150.00,INV-001,TXN-001,PK00ABC,PKR")
                ->helperText('Use upload or paste, not both. Required columns are transaction date and amount.'),
            TextInput::make('balance_start')->id('recon-import-balance-start')
                ->label('Statement opening balance')
                ->rules(['regex:/^-?(?:0|[1-9]\\d{0,17})(?:\\.\\d{1,4})?$/'])
                ->required(),
            TextInput::make('balance_end_real')->id('recon-import-balance-end')
                ->label('Statement closing balance (from bank)')
                ->rules(['regex:/^-?(?:0|[1-9]\\d{0,17})(?:\\.\\d{1,4})?$/'])
                ->required(),
            DatePicker::make('statement_start_date')->id('recon-import-period-start')->label('Statement period from')->required(),
            DatePicker::make('statement_end_date')->id('recon-import-period-end')->label('Statement period to')->required(),
            DatePicker::make('statement_date')->id('recon-import-statement-date')->label('Statement date')->required(),
            TextInput::make('account_identifier')
                ->id('recon-import-account-identifier')
                ->label('Statement account number / IBAN')
                ->helperText('Must identify the company bank account linked to this reconciliation.')
                ->required(),
            TextInput::make('currency_code')->id('recon-import-currency')->label('Statement currency')->maxLength(3)->required(),
            TextInput::make('control_debit_total')->id('recon-import-control-debit')->label('Bank-provided debit total')->helperText('Optional independent control total.'),
            TextInput::make('control_credit_total')->id('recon-import-control-credit')->label('Bank-provided credit total')->helperText('Optional independent control total.'),
            TextInput::make('control_transaction_count')->id('recon-import-control-count')->label('Bank-provided transaction count')->integer()->minValue(0)->helperText('Optional independent control count.'),
            Toggle::make('adopt_statement_period')
                ->id('recon-import-adopt-period')
                ->label('Adopt the statement period for this reconciliation')
                ->live()
                ->helperText('Off by default. Enable only when you intentionally want to replace the reconciliation period.'),
            Textarea::make('period_change_reason')
                ->id('recon-import-period-change-reason')
                ->label('Reason for changing the reconciliation period')
                ->rows(2)
                ->visible(fn ($get): bool => (bool) $get('adopt_statement_period'))
                ->required(fn ($get): bool => (bool) $get('adopt_statement_period')),
        ])->statePath('importData');
    }

    public function exceptionForm(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('classification')
                ->id('recon-exception-classification')
                ->options(collect(ReconcilingItemClassification::cases())->mapWithKeys(fn ($case) => [$case->value => str($case->value)->replace('_', ' ')->title()->toString()]))
                ->live()
                ->afterStateUpdated(function ($state, $get, $set): void {
                    $this->copyStatementExceptionAmount($state, $get('statement_line_id'), $set);
                })
                ->required(),
            Select::make('side')->id('recon-exception-side')->options(['bank' => 'Bank', 'book' => 'Book'])->required(),
            Select::make('statement_line_id')
                ->id('recon-exception-statement-line')
                ->label('Statement line')
                ->options(fn () => $this->statementLineOptions())
                ->live()
                ->afterStateUpdated(function ($state, $get, $set): void {
                    $this->copyStatementExceptionAmount($get('classification'), $state, $set);
                })
                ->searchable(),
            Select::make('move_line_id')
                ->id('recon-exception-move-line')
                ->label('Posted liquidity move line (book-side exceptions)')
                ->options(fn () => $this->postedLiquidityLineOptions())
                ->searchable()
                ->helperText('Optional for bank-side items; required when linking an outstanding or missing book item to posted GL evidence.'),
            TextInput::make('amount')
                ->id('recon-exception-amount')
                ->numeric()
                ->helperText('For bank errors this is the correction to the bank balance, so it is automatically the opposite sign of the erroneous statement line.')
                ->required(),
            Select::make('owner_id')
                ->id('recon-exception-owner')
                ->label('Owner')
                ->options(fn () => User::query()
                    ->where('is_active', true)
                    ->where(fn ($query) => $query
                        ->where('default_company_id', current_company_id())
                        ->orWhereHas('allowedCompanies', fn ($companies) => $companies->whereKey(current_company_id())))
                    ->orderBy('name')->pluck('name', 'id'))
                ->searchable(),
            Textarea::make('evidence')->id('recon-exception-evidence')->label('Evidence/reference')->rows(2),
            Textarea::make('notes')->id('recon-exception-notes')->rows(2),
        ])->statePath('exceptionData');
    }

    public function transferForm(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('amount')->id('recon-transfer-amount')->label('Amount')->numeric()->required(),
            Select::make('destination_liquidity_account_id')
                ->id('recon-transfer-destination')
                ->label('Destination bank / clearing account')
                ->options(fn () => $this->accountOptions(['asset_cash', 'asset_current']))
                ->searchable()->required(),
            Select::make('statement_line_id')->id('recon-transfer-statement-line')->label('Bank statement line resolved')->options(fn () => $this->statementLineOptions())->searchable()->required(),
            Textarea::make('notes')->id('recon-transfer-notes')->label('Transfer note')->rows(2),
        ])->statePath('transferData');
    }

    public function fxForm(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('amount')->id('recon-fx-amount')->label('FX difference (+ gain / − loss)')->numeric()->required(),
            Select::make('gain_account_id')->id('recon-fx-gain-account')->label('FX gain account')->options(fn () => $this->accountOptions(['income', 'income_other']))->searchable()->required(),
            Select::make('loss_account_id')->id('recon-fx-loss-account')->label('FX loss account')->options(fn () => $this->accountOptions(['expense', 'expense_direct_cost']))->searchable()->required(),
            Select::make('statement_line_id')->id('recon-fx-statement-line')->label('Bank statement line resolved')->options(fn () => $this->statementLineOptions())->searchable()->required(),
            Textarea::make('notes')->id('recon-fx-notes')->label('FX explanation')->rows(2),
        ])->statePath('fxData');
    }

    public function returnedPaymentForm(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('payment_id')
                ->id('recon-returned-payment')
                ->label('Posted customer/vendor payment to reverse')
                ->options(fn () => Payment::query()
                    ->where('company_id', $this->record->company_id)
                    ->where('currency_id', $this->record->currency_id)
                    ->whereNotNull('move_id')
                    ->whereHas('move', fn ($query) => $query->where('state', 'posted'))
                    ->with('partner')->latest('id')->limit(200)->get()->mapWithKeys(fn (Payment $payment): array => [
                        $payment->id => '#'.$payment->id.' · '.($payment->partner?->name ?: 'No partner').' · '.($payment->payment_reference ?: $payment->name ?: 'No reference').' · '.number_format((float) $payment->amount, 2),
                    ]))
                ->searchable()->required(),
            Select::make('statement_line_id')->id('recon-returned-statement-line')->label('Returned bank statement line')->options(fn () => $this->statementLineOptions())->searchable()->required(),
            Textarea::make('reason')->id('recon-returned-reason')->label('Return reason')->rows(2)->required(),
        ])->statePath('returnedPaymentData');
    }

    private function statementLineOptions(): array
    {
        return StatementLine::query()
            ->where('statement_id', $this->record->statement_id)
            ->where('company_id', $this->record->company_id)
            ->where('journal_id', $this->record->journal_id)
            ->where('currency_id', $this->record->currency_id)
            ->where('import_status', 'accepted')
            ->whereNull('terminal_treatment')
            ->orderBy('transaction_date')->get()->mapWithKeys(fn (StatementLine $line): array => [
                $line->id => $line->transaction_date?->format('d M Y').' · '.($line->payment_reference ?: 'No reference').' · '.$line->amount,
            ])->all();
    }

    private function copyStatementExceptionAmount(mixed $classification, mixed $statementLineId, callable $set): void
    {
        if (! $statementLineId || ! in_array($classification, ['bank_error', 'duplicate'], true)) {
            return;
        }

        $line = StatementLine::query()
            ->whereKey($statementLineId)
            ->where('statement_id', $this->record->statement_id)
            ->where('company_id', $this->record->company_id)
            ->first();
        if (! $line) {
            return;
        }

        $sourceAmount = bcadd((string) ($line->amount_residual ?? $line->amount), '0', 4);
        $set('amount', $classification === 'bank_error' ? bcmul($sourceAmount, '-1', 4) : $sourceAmount);
    }

    private function accountOptions(array $types): array
    {
        return Account::query()->withoutGlobalScopes()->where(owned_by_company($this->record->company_id))->whereIn('account_type', $types)->where('deprecated', false)->orderBy('code')->get()->mapWithKeys(fn (Account $account): array => [
            $account->id => $account->code.' — '.$account->name,
        ])->all();
    }

    private function postedLiquidityLineOptions(): array
    {
        return MoveLine::query()->withoutGlobalScopes()->from('accounts_account_move_lines as line')
            ->join('accounts_account_moves as move', 'move.id', '=', 'line.move_id')
            ->where('line.company_id', $this->record->company_id)
            ->where('line.account_id', $this->record->liquidity_account_id)
            ->where('line.currency_id', $this->record->currency_id)
            ->where('move.company_id', $this->record->company_id)
            ->where('move.state', 'posted')
            ->orderByDesc('line.date')->limit(200)->get(['line.id', 'line.name', 'move.name as move_name', 'line.balance'])
            ->mapWithKeys(fn ($line): array => [$line->id => ($line->move_name ?: 'Move').' · '.($line->name ?: 'Liquidity line').' · '.$line->balance])->all();
    }

    public function previewImport(): void
    {
        $state = $this->importForm->getState();
        try {
            $source = $this->readImportSource($state);
            $parsed = app(CsvStatementParser::class)->parse($source['contents']);
            $this->importPreview = [
                ...$parsed,
                'rows'               => array_slice($parsed['rows'], 0, 10),
                'count'              => count($parsed['rows']),
                'filename'           => $source['filename'],
                'raw_content_hash'   => hash('sha256', $source['contents']),
                'calculated_closing' => bcadd((string) $state['balance_start'], $parsed['net_total'], 4),
                'declared_closing'   => bcadd((string) $state['balance_end_real'], '0', 4),
                'period_changed'     => (string) $state['statement_start_date'] !== $this->record->period_start?->toDateString()
                    || (string) $state['statement_end_date'] !== $this->record->period_end?->toDateString(),
            ];
            $this->importResult = null;
        } catch (\Throwable $exception) {
            $this->importPreview = ['rows' => [], 'count' => 0, 'errors' => [$exception->getMessage()]];
        }
    }

    public function importStatement(): void
    {
        $this->authorizeEdit();

        $state = $this->importForm->getState();

        try {
            $source = $this->readImportSource($state);
            $parsed = app(CsvStatementParser::class)->parse($source['contents']);
            $batch = app(StatementImporter::class)->importCsv(
                $this->record,
                $parsed['rows'],
                (string) $state['balance_start'],
                (string) $state['balance_end_real'],
                $source['filename'],
                options: [
                    'source_type'               => $source['source_type'],
                    'source_storage_path'       => $source['storage_path'],
                    'raw_content_hash'          => hash('sha256', $source['contents']),
                    'encoding'                  => $parsed['encoding'],
                    'delimiter'                 => $parsed['delimiter'],
                    'mapping'                   => $parsed['mapping'],
                    'parser_version'            => 'mapped-csv-v2',
                    'preflight_errors'          => $parsed['errors'],
                    'statement_start_date'      => (string) $state['statement_start_date'],
                    'statement_end_date'        => (string) $state['statement_end_date'],
                    'statement_date'            => (string) $state['statement_date'],
                    'account_identifier'        => (string) $state['account_identifier'],
                    'currency_code'             => (string) $state['currency_code'],
                    'control_debit_total'       => $state['control_debit_total'] ?? null,
                    'control_credit_total'      => $state['control_credit_total'] ?? null,
                    'control_transaction_count' => $state['control_transaction_count'] ?? null,
                    'adopt_statement_period'    => (bool) ($state['adopt_statement_period'] ?? false),
                    'period_change_reason'      => $state['period_change_reason'] ?? null,
                ],
            );
            $errors = $batch->error_report ?? [];
            $this->importResult = [
                'id'              => $batch->id, 'status' => $batch->status, 'filename' => $batch->original_filename,
                'lines_total'     => $batch->lines_total, 'lines_imported' => $batch->lines_imported,
                'lines_duplicate' => $batch->lines_duplicate, 'errors' => $errors,
                'content_hash'    => $batch->content_hash, 'parser_version' => $batch->parser_version,
            ];
            if ($batch->status === 'accepted') {
                $this->dispatch('notify', message: sprintf('Statement accepted: %d lines imported.', $batch->lines_imported), type: 'success');
                $this->importForm->fill();
                $this->importPreview = null;
                $this->record->refresh();
            } else {
                $this->dispatch('notify', message: 'Statement quarantined. Review the errors below, correct the source, and retry.', type: 'warning');
            }
        } catch (\Throwable $e) {
            $this->dispatch('notify', message: 'Import failed: '.$e->getMessage(), type: 'danger');
        }
    }

    /** @return array{contents: string, filename: string, source_type: string, storage_path: string} */
    private function readImportSource(array $state): array
    {
        $file = $state['statement_file'] ?? null;
        $pasted = trim((string) ($state['csv'] ?? ''));
        if ($file && $pasted !== '') {
            throw new InvalidArgumentException('Choose either a CSV file or pasted CSV, not both.');
        }
        if ($file) {
            $path = is_array($file) ? (string) reset($file) : (string) $file;
            if (! Storage::disk('local')->exists($path)) {
                throw new InvalidArgumentException('The uploaded CSV could not be read. Upload it again.');
            }

            $originalName = $state['statement_file_name'] ?? basename($path);
            $originalName = is_array($originalName) ? (string) reset($originalName) : (string) $originalName;

            return ['contents' => Storage::disk('local')->get($path), 'filename' => $originalName, 'source_type' => 'uploaded_csv', 'storage_path' => 'local:'.$path];
        }
        if ($pasted === '') {
            throw new InvalidArgumentException('Upload a bank statement CSV or paste CSV data.');
        }

        return ['contents' => $pasted, 'filename' => 'pasted.csv', 'source_type' => 'pasted_csv', 'storage_path' => 'database:recon_import_batches.source_metadata'];
    }

    /* ------------------------------------------------------------------
     |  Data for the view
     | ----------------------------------------------------------------- */

    public function getBankLinesProperty(): LengthAwarePaginator
    {
        return StatementLine::where('statement_id', $this->record->statement_id)
            ->when($this->bankStatus === 'open', fn ($query) => $query->whereNull('terminal_treatment'))
            ->when($this->bankStatus === 'reconciled', fn ($query) => $query->whereNotNull('terminal_treatment'))
            ->when(trim($this->bankSearch) !== '', function ($query): void {
                $term = '%'.strtolower(trim($this->bankSearch)).'%';
                $query->where(fn ($search) => $search
                    ->whereRaw('LOWER(COALESCE(partner_name, \'\')) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(COALESCE(payment_reference, \'\')) LIKE ?', [$term]));
            })
            ->orderBy('id')
            ->paginate(50, ['*'], 'bankPage')
            ->through(function ($line) {
                $details = json_decode((string) $line->transaction_details, true) ?: [];
                $line->is_duplicate = (bool) ($details['duplicate'] ?? false);

                return $line;
            });
    }

    public function getImportBatchProperty(): ?ReconImportBatch
    {
        return $this->record->importBatches()->latest('id')->first();
    }

    public function getLedgerCandidatesProperty(): LengthAwarePaginator
    {
        return app(MatchingEngine::class)->ledgerCandidates($this->record)
            ->when(trim($this->ledgerSearch) !== '', function ($query): void {
                $term = '%'.strtolower(trim($this->ledgerSearch)).'%';
                $query->where(fn ($search) => $search
                    ->whereRaw('LOWER(COALESCE(move.name, \'\')) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(COALESCE(move.reference, \'\')) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(COALESCE(line.name, \'\')) LIKE ?', [$term]));
            })
            ->paginate(50, ['*'], 'ledgerPage');
    }

    public function getProposalsProperty(): EloquentCollection
    {
        return $this->record->matches()->where('status', 'proposed')->with('lines')->get();
    }

    public function getConfirmedMatchesProperty(): EloquentCollection
    {
        return $this->record->matches()->where('status', 'confirmed')->with('lines')->get();
    }

    public function getSummaryProperty(): array
    {
        $svc = app(FinalizationService::class);
        $check = $svc->completionCheck($this->record);

        return array_merge($check['difference'], [
            'status'     => $this->record->status,
            'can_edit'   => in_array($this->record->status, ReconciliationMutationGuard::EDITABLE_STATUSES, true)
                && Gate::allows('update', $this->record),
            'check'      => $check,
            'exceptions' => $svc->exceptions($this->record),
        ]);
    }

    /** @return array<int, array<string, mixed>> */
    public function getReopenImpactProperty(): array
    {
        if (! $this->record || ! in_array($this->record->status, ['completed', 'reopen_requested'], true)) {
            return [];
        }

        if (Gate::denies('reopen', $this->record)) {
            return [];
        }

        return app(ReconciliationLifecycleService::class)->previewReopenImpact($this->record);
    }

    /* ------------------------------------------------------------------
     |  Actions (called from the Blade view via wire:click)
     | ----------------------------------------------------------------- */

    public function runAutoMatch(): void
    {
        $this->authorizeEdit();

        try {
            $stats = app(MatchingEngine::class)->autoMatch($this->record, confirmStrong: false);
        } catch (InvalidArgumentException|\RuntimeException $exception) {
            $this->dispatch('notify', message: 'Could not find matches: '.$exception->getMessage(), type: 'danger');

            return;
        }

        $count = $stats['proposed'] + $stats['confirmed'];
        $this->dispatch(
            'notify',
            message: $count === 0
                ? 'No eligible matches were found. Check amount, direction, date, and reference.'
                : "Found {$count} suggested match(es). Review and confirm them below.",
            type: $count === 0 ? 'warning' : 'success',
        );
    }

    public function confirmMatch(int $matchId): void
    {
        $this->authorizeEdit();

        $match = $this->record->matches()->findOrFail($matchId);

        try {
            app(MatchService::class)->confirm($match);
        } catch (InvalidArgumentException $e) {
            $this->dispatch('notify', message: $e->getMessage(), type: 'danger');
        }
    }

    public function rejectMatch(int $matchId): void
    {
        $this->authorizeEdit();

        app(MatchService::class)->reject($this->record->matches()->findOrFail($matchId));
    }

    public function unmatchConfirmed(int $matchId): void
    {
        $this->authorizeEdit();

        try {
            app(MatchService::class)->unmatch(
                $this->record->matches()->findOrFail($matchId),
                (string) ($this->unmatchReasons[$matchId] ?? ''),
            );
            unset($this->unmatchReasons[$matchId]);
            $this->dispatch('notify', message: 'Match reversed. The reason was saved in the audit trail.', type: 'success');
        } catch (InvalidArgumentException $e) {
            $this->dispatch('notify', message: $e->getMessage(), type: 'danger');
        }
    }

    public function toggleBank(int $id): void
    {
        if (in_array($id, $this->bankSelection, true)) {
            $this->bankSelection = array_values(array_diff($this->bankSelection, [$id]));
            unset($this->allocations['bank'][$id]);

            return;
        }

        $this->bankSelection[] = $id;
        if (! $this->record) {
            return;
        }

        $this->allocations['bank'][$id] = app(MatchingEngine::class)->bankRemainingFor($this->record, $id);
    }

    public function toggleLedger(int $id): void
    {
        if (in_array($id, $this->ledgerSelection, true)) {
            $this->ledgerSelection = array_values(array_diff($this->ledgerSelection, [$id]));
            unset($this->allocations['ledger'][$id]);

            return;
        }

        $this->ledgerSelection[] = $id;
        if (! $this->record) {
            return;
        }

        $this->allocations['ledger'][$id] = app(MatchingEngine::class)->ledgerRemainingFor($this->record, $id);
    }

    public function clearSelection(): void
    {
        $this->bankSelection = [];
        $this->ledgerSelection = [];
        $this->allocations = ['bank' => [], 'ledger' => []];
    }

    public function getBasketCheckProperty(): array
    {
        if (empty($this->bankSelection) || empty($this->ledgerSelection)) {
            return ['balanced' => false, 'bank_total' => 0, 'ledger_total' => 0, 'variance' => 0];
        }

        try {
            return app(MatchingEngine::class)->validateBasket($this->record, $this->bankSelection, $this->ledgerSelection, $this->normalizedAllocations());
        } catch (InvalidArgumentException $exception) {
            return ['balanced' => false, 'bank_total' => 0, 'ledger_total' => 0, 'variance' => 0, 'error' => $exception->getMessage()];
        }
    }

    public function confirmBasket(): void
    {
        $this->authorizeEdit();

        try {
            app(MatchService::class)->createMatch(
                $this->record,
                $this->bankSelection,
                $this->ledgerSelection,
                allocations: $this->normalizedAllocations(),
            );

            $this->bankSelection = [];
            $this->ledgerSelection = [];
            $this->allocations = ['bank' => [], 'ledger' => []];
        } catch (InvalidArgumentException $e) {
            $this->dispatch('notify', message: $e->getMessage(), type: 'danger');
        }
    }

    public function postAdjustment(): void
    {
        $this->authorizeEdit();

        $state = $this->adjustmentForm->getState();

        try {
            app(AdjustmentService::class)->post(
                $this->record,
                $state['type'],
                $state['amount'],
                $state['counterpart_account_id'] ?? null,
                $state['notes'] ?? null,
                $state['statement_line_id'] ?? null,
            );

            $this->adjustmentForm->fill();
        } catch (InvalidArgumentException|\RuntimeException $e) {
            $this->dispatch('notify', message: $e->getMessage(), type: 'danger');
        }
    }

    public function postTransferAdjustment(): void
    {
        $this->authorizeEdit();
        $state = $this->transferForm->getState();

        try {
            app(AdjustmentService::class)->postTransfer(
                $this->record,
                $state['amount'],
                (int) $state['destination_liquidity_account_id'],
                $state['notes'] ?? null,
                (int) $state['statement_line_id'],
            );
            $this->transferForm->fill();
        } catch (InvalidArgumentException|\RuntimeException $e) {
            $this->dispatch('notify', message: $e->getMessage(), type: 'danger');
        }
    }

    public function postFxAdjustment(): void
    {
        $this->authorizeEdit();
        $state = $this->fxForm->getState();

        try {
            app(AdjustmentService::class)->postFxDifference(
                $this->record,
                $state['amount'],
                (int) $state['gain_account_id'],
                (int) $state['loss_account_id'],
                $state['notes'] ?? null,
                (int) $state['statement_line_id'],
            );
            $this->fxForm->fill();
        } catch (InvalidArgumentException|\RuntimeException $e) {
            $this->dispatch('notify', message: $e->getMessage(), type: 'danger');
        }
    }

    public function postReturnedPaymentAdjustment(): void
    {
        $this->authorizeEdit();
        $state = $this->returnedPaymentForm->getState();

        try {
            $payment = Payment::query()->where('company_id', $this->record->company_id)->findOrFail($state['payment_id']);
            app(AdjustmentService::class)->postReturnedPayment(
                $this->record,
                $payment,
                (int) $state['statement_line_id'],
                (string) $state['reason'],
            );
            $this->returnedPaymentForm->fill();
        } catch (InvalidArgumentException|\RuntimeException $e) {
            $this->dispatch('notify', message: $e->getMessage(), type: 'danger');
        }
    }

    public function classifyException(): void
    {
        $this->authorizeEdit();
        $state = $this->exceptionForm->getState();

        try {
            app(ReconciliationExceptionService::class)->classify(
                $this->record,
                ReconcilingItemClassification::from($state['classification']),
                ReconcilingItemSide::from($state['side']),
                $state['amount'],
                $state['statement_line_id'] ?? null,
                $state['move_line_id'] ?? null,
                ownerId: $state['owner_id'] ?? null,
                evidence: ['reference' => $state['evidence'] ?? null],
                notes: $state['notes'] ?? null,
            );
            $this->exceptionForm->fill();
        } catch (InvalidArgumentException $e) {
            $this->dispatch('notify', message: $e->getMessage(), type: 'danger');
        }
    }

    public function approveBankError(int $itemId): void
    {
        $this->approveException($itemId, false);
    }

    public function excludeDuplicate(int $itemId): void
    {
        $this->approveException($itemId, true);
    }

    public function carryForwardException(int $itemId): void
    {
        $this->authorizeEdit();

        try {
            $item = $this->record->reconcilingItems()->findOrFail($itemId);
            app(ReconciliationExceptionService::class)->carryForwardTimingDifference(
                $item,
                (string) ($this->exceptionReasons[$itemId] ?? ''),
                $item->owner_id,
            );
            unset($this->exceptionReasons[$itemId]);
        } catch (InvalidArgumentException $exception) {
            $this->dispatch('notify', message: $exception->getMessage(), type: 'danger');
        }
    }

    public function resolveCorrectedException(int $itemId): void
    {
        $this->authorizeEdit();

        try {
            $item = $this->record->reconcilingItems()->findOrFail($itemId);
            $adjustment = $this->record->adjustments()
                ->where('statement_line_id', $item->statement_line_id)
                ->whereNotNull('posted_at')
                ->whereNull('reversed_at')
                ->latest('id')
                ->first();
            if (! $adjustment) {
                throw new InvalidArgumentException('Post and link the correcting adjustment before resolving this exception.');
            }

            app(ReconciliationExceptionService::class)->resolveWithAdjustment(
                $item,
                $adjustment,
                (string) ($this->exceptionReasons[$itemId] ?? ''),
            );
            unset($this->exceptionReasons[$itemId]);
        } catch (InvalidArgumentException $exception) {
            $this->dispatch('notify', message: $exception->getMessage(), type: 'danger');
        }
    }

    private function approveException(int $itemId, bool $duplicate): void
    {
        $this->authorizeEdit();

        try {
            $item = $this->record->reconcilingItems()->findOrFail($itemId);
            $reason = $this->exceptionReasons[$itemId] ?? '';
            $service = app(ReconciliationExceptionService::class);

            $duplicate
                ? $service->excludeDuplicate($item, $reason)
                : $service->approveBankError($item, $reason);

            unset($this->exceptionReasons[$itemId]);
        } catch (InvalidArgumentException $exception) {
            $this->dispatch('notify', message: $exception->getMessage(), type: 'danger');
        }
    }

    public function startReview(): void
    {
        $this->record = app(ReconciliationLifecycleService::class)->startReview($this->record->refresh());
    }

    public function backToInProgress(): void
    {
        $this->record = app(ReconciliationLifecycleService::class)->returnToPreparation($this->record->refresh());
    }

    public function complete(): void
    {
        try {
            $this->record = app(FinalizationService::class)->complete($this->record->refresh());
            Notification::make()
                ->title('Reconciliation completed')
                ->body('The reconciliation was locked and its audit snapshot was saved.')
                ->success()
                ->send();
        } catch (InvalidArgumentException $e) {
            Notification::make()
                ->title('Completion blocked')
                ->body($e->getMessage())
                ->danger()
                ->persistent()
                ->send();
        }
    }

    public function reopen(): void
    {
        try {
            $this->record = app(ReconciliationLifecycleService::class)->requestReopen($this->record->refresh(), $this->reopenReason);
        } catch (InvalidArgumentException $e) {
            $this->dispatch('notify', message: $e->getMessage(), type: 'danger');
        }
    }

    public function approveReopen(): void
    {
        try {
            $this->record = app(ReconciliationLifecycleService::class)->reopen($this->record->refresh(), $this->record->reopen_request_reason ?? $this->reopenReason);
        } catch (InvalidArgumentException $e) {
            $this->dispatch('notify', message: $e->getMessage(), type: 'danger');
        }
    }

    public function reworkStale(): void
    {
        try {
            $this->record = app(ReconciliationLifecycleService::class)->reworkStale($this->record, $this->reopenReason);
            $this->reopenReason = '';
            $this->dispatch('notify', message: 'Stale reconciliation opened for controlled rework.', type: 'success');
        } catch (InvalidArgumentException $e) {
            $this->dispatch('notify', message: $e->getMessage(), type: 'danger');
        }
    }

    protected function authorizeEdit(): void
    {
        Gate::authorize('update', $this->record);

        $status = $this->record->fresh()->status;
        if (! in_array($status, ReconciliationMutationGuard::EDITABLE_STATUSES, true)) {
            throw new InvalidArgumentException('Reconciliation is locked (status: '.$status.'). Use the permitted lifecycle action before changing evidence.');
        }
    }

    private function normalizedAllocations(): array
    {
        return [
            'bank'   => array_filter($this->allocations['bank'] ?? [], fn ($amount) => $amount !== null && $amount !== ''),
            'ledger' => array_filter($this->allocations['ledger'] ?? [], fn ($amount) => $amount !== null && $amount !== ''),
        ];
    }
}
