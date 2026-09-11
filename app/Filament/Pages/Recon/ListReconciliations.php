<?php

namespace App\Filament\Pages\Recon;

use App\Models\Recon\BankReconciliation;
use App\Services\Recon\BankOpeningBalanceService;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;
use Webkul\Account\Enums\AccountType;
use Webkul\Account\Models\Account;
use Webkul\Account\Models\Journal;
use Webkul\Accounting\Filament\Clusters\Configuration\Resources\CompanyBankAccountResource;
use Webkul\Support\Enums\NavigationGroup;

class ListReconciliations extends Page implements HasTable
{
    use HasPageShield, InteractsWithTable;

    protected string $view = 'filament.pages.recon.list';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $slug = 'bank-reconciliations';

    protected static ?int $navigationSort = 2;

    protected static function getPagePermission(): ?string
    {
        return 'page_filament_list_reconciliations';
    }

    public static function getNavigationLabel(): string
    {
        return 'Bank Reconciliations';
    }

    public function getTitle(): string
    {
        return 'Bank Reconciliations';
    }

    public static function getNavigationGroup(): string|\UnitEnum
    {
        return NavigationGroup::Accounting;
    }

    public function mount(): void
    {
        Gate::authorize('viewAny', BankReconciliation::class);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(BankReconciliation::query()->where('company_id', current_company_id())->latest('id'))
            ->columns([
                TextColumn::make('name')->label('Reconciliation')->searchable(),
                TextColumn::make('journal.name')->label('Journal'),
                TextColumn::make('status')
                    ->formatStateUsing(fn ($state): string => str($state instanceof \BackedEnum ? $state->value : $state)
                        ->replace('_', ' ')
                        ->title()
                        ->toString())
                    ->badge()
                    ->colors([
                        'draft'            => 'gray',
                        'in_progress'      => 'warning',
                        'review'           => 'info',
                        'completed'        => 'success',
                        'reopened'         => 'danger',
                        'reopen_requested' => 'danger',
                        'stale'            => 'danger',
                    ]),
                TextColumn::make('period_start')->date('d M Y')->label('From'),
                TextColumn::make('period_end')->date('d M Y')->label('To'),
                TextColumn::make('balance_start')->numeric(2)->label('Start'),
                TextColumn::make('balance_end_real')->numeric(2)->label('Bank end'),
                TextColumn::make('difference')
                    ->numeric(2)
                    ->color(fn ($state) => abs((float) $state) < 0.005 ? 'success' : 'danger')
                    ->label('Difference'),
                TextColumn::make('completed_at')->dateTime('d M Y H:i')->label('Completed'),
            ])
            ->recordActions([
                Action::make('open')
                    ->label('Open')
                    ->icon('heroicon-o-pencil-square')
                    ->url(fn (BankReconciliation $record) => ReconcileWorkspace::getUrl(['record' => $record->id])),
                Action::make('report')
                    ->label('Report')
                    ->icon('heroicon-o-document-chart-bar')
                    ->color('gray')
                    ->url(fn (BankReconciliation $record) => ReconReport::getUrl(['record' => $record->id])),
            ])
            ->headerActions([
                Action::make('bankAccounts')
                    ->label('Manage Bank Accounts')
                    ->icon('heroicon-o-building-library')
                    ->color('gray')
                    ->url(CompanyBankAccountResource::getUrl()),
                Action::make('setOpeningBalance')
                    ->label('Set Bank Opening Balance')
                    ->icon('heroicon-o-scale')
                    ->color('warning')
                    ->visible(fn (): bool => Gate::allows('postOpeningBalance', BankReconciliation::class))
                    ->schema([
                        Select::make('journal_id')
                            ->label('Bank journal')
                            ->options(fn (): array => Journal::query()
                                ->where('company_id', current_company_id())
                                ->where('type', 'bank')
                                ->with('bankAccount')
                                ->get()
                                ->filter(fn (Journal $journal): bool => $journal->isReadyForReconciliation())
                                ->mapWithKeys(fn (Journal $journal): array => [
                                    $journal->id => $journal->name.' · '.$journal->bankAccount?->account_number,
                                ])
                                ->all())
                            ->helperText('Use once when onboarding a bank account. This posts a real Bank-versus-Equity journal entry; it never overrides reconciliation totals.')
                            ->required(),
                        Select::make('counterpart_account_id')
                            ->label('Opening balance equity account')
                            ->options(fn (): array => Account::query()
                                ->whereIn('account_type', [AccountType::EQUITY, AccountType::EQUITY_UNAFFECTED])
                                ->whereHas('companies', fn ($query) => $query->whereKey(current_company_id()))
                                ->where('deprecated', false)
                                ->orderBy('code')
                                ->get()
                                ->mapWithKeys(fn (Account $account): array => [
                                    $account->id => trim(($account->code ? $account->code.' · ' : '').$account->name),
                                ])
                                ->all())
                            ->helperText('Normally select Opening Balance Equity. Do not use income or expense accounts for migration opening balances.')
                            ->searchable()
                            ->required(),
                        DatePicker::make('effective_date')
                            ->label('Effective date')
                            ->helperText('For a first September reconciliation, use 31 August—the day before the statement period begins.')
                            ->required(),
                        TextInput::make('amount')
                            ->label('Opening balance')
                            ->helperText('Enter a positive amount for cash held by the bank. Enter a negative amount only for an overdraft.')
                            ->numeric()
                            ->required(),
                        Textarea::make('notes')
                            ->label('Explanation')
                            ->helperText('State the migration source, for example: “Bank statement closing balance at 31 Aug 2026”.')
                            ->required()
                            ->maxLength(1000),
                    ])
                    ->action(function (array $data): void {
                        Gate::authorize('postOpeningBalance', BankReconciliation::class);

                        $openingBalance = app(BankOpeningBalanceService::class)
                            ->post((int) current_company_id(), $data);

                        Notification::make()
                            ->title('Bank opening balance posted')
                            ->body("Posted journal entry #{$openingBalance->move_id}. Refresh any open reconciliation to recalculate its GL balance.")
                            ->success()
                            ->send();
                    }),
                Action::make('create')
                    ->label('New Reconciliation')
                    ->icon('heroicon-o-plus-circle')
                    ->visible(fn (): bool => Gate::allows('create', BankReconciliation::class))
                    ->disabled(function (): bool {
                        $companyId = current_company_id();

                        return ! $companyId || ! Journal::query()
                            ->where('company_id', $companyId)
                            ->where('type', 'bank')
                            ->get()
                            ->contains(fn (Journal $journal): bool => $journal->isReadyForReconciliation());
                    })
                    ->schema([
                        Select::make('journal_id')
                            ->label('Bank journal')
                            ->options(function () {
                                return Journal::query()
                                    ->where('company_id', current_company_id())
                                    ->where('type', 'bank')
                                    ->with(['bankAccount', 'company'])
                                    ->get()
                                    ->mapWithKeys(fn (Journal $journal): array => [
                                        $journal->id => $journal->name.($journal->isReadyForReconciliation() ? '' : ' (needs bank account setup)'),
                                    ])
                                    ->all();
                            })
                            ->disableOptionWhen(function ($value): bool {
                                $journal = Journal::query()->find($value);

                                return ! $journal?->isReadyForReconciliation();
                            })
                            ->helperText(fn (): HtmlString => new HtmlString(
                                'Only journals linked to a bank account and default ledger account can be reconciled. '
                                .'<a href="'.e(CompanyBankAccountResource::getUrl()).'" class="font-medium text-primary-600 hover:underline">Set up or manage a company bank account</a>. '
                                .'The opening GL balance is calculated from posted entries; use “Set Bank Opening Balance” once if this is a new bank ledger.'
                            ))
                            ->required()
                            ->live(),
                        TextInput::make('name')
                            ->label('Name')
                            ->default(fn ($get) => 'REC-'.now()->format('Ymd'))
                            ->required(),
                        DatePicker::make('period_start')->label('Period from')->required(),
                        DatePicker::make('period_end')
                            ->label('Period to')
                            ->required()
                            ->afterOrEqual('period_start'),
                    ])
                    ->action(function (array $data) {
                        Gate::authorize('create', BankReconciliation::class);

                        $journal = Journal::query()
                            ->where('company_id', current_company_id())
                            ->find($data['journal_id']);

                        if (! $journal?->isReadyForReconciliation()) {
                            throw ValidationException::withMessages([
                                'journal_id' => $journal
                                    ? 'This journal needs '.implode(', ', $journal->reconciliationConfigurationErrors()).'.'
                                    : 'Select a bank journal for the active company. Cash-on-hand uses a separate cash-count workflow.',
                            ]);
                        }

                        $rec = BankReconciliation::create([
                            'company_id'       => current_company_id(),
                            'journal_id'       => $data['journal_id'],
                            'name'             => $data['name'],
                            'status'           => 'draft',
                            'period_start'     => $data['period_start'] ?? null,
                            'period_end'       => $data['period_end'] ?? null,
                            // The calculator derives this from posted Bank GL lines. It must never be user-entered.
                            'gl_balance_start' => 0,
                            'creator_id'       => Auth::id(),
                        ]);

                        BankReconciliation::logEvent($rec, 'created', ['journal_id' => $data['journal_id']]);

                        return redirect(ReconcileWorkspace::getUrl(['record' => $rec->id]));
                    }),
            ]);
    }
}
