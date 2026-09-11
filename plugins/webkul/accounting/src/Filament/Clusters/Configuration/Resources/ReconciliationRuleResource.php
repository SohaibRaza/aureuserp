<?php

namespace Webkul\Accounting\Filament\Clusters\Configuration\Resources;

use App\Models\Recon\ReconciliationRule;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;
use Webkul\Accounting\Filament\Clusters\Configuration;
use Webkul\Accounting\Filament\Clusters\Configuration\Resources\ReconciliationRuleResource\Pages\CreateReconciliationRule;
use Webkul\Accounting\Filament\Clusters\Configuration\Resources\ReconciliationRuleResource\Pages\EditReconciliationRule;
use Webkul\Accounting\Filament\Clusters\Configuration\Resources\ReconciliationRuleResource\Pages\ListReconciliationRules;
use Webkul\Partner\Models\Partner;

class ReconciliationRuleResource extends Resource
{
    protected static ?string $model = ReconciliationRule::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-adjustments-horizontal';

    protected static ?string $cluster = Configuration::class;

    protected static ?int $navigationSort = 2;

    public static function getNavigationLabel(): string
    {
        return 'Reconciliation Rules';
    }

    public static function getNavigationGroup(): ?string
    {
        return 'Banking';
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('company_id', current_company_id());
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required()->maxLength(255)
                ->live()
                ->label(self::labelWithGuide('Rule name', 'Rule name', '<strong>What it means</strong><br>The name identifies this matching rule in the rule list and on matches created by it.<br><br><strong>What to enter</strong><br>Use a specific name that describes the transaction. For example, “Monthly bank fee”.<br><br><strong>What effect it has</strong><br>Saving another rule with the same name creates a new version and turns off the previous version.', 'A version is a saved revision of the rule. Use the same name only when the new rule is intended to replace the old one.'))
                ->placeholder('Example: Monthly bank fee'),
            Select::make('journal_id')->label(self::labelWithGuide('Apply this rule to', 'Apply this rule to', '<strong>What it means</strong><br>This sets which bank or cash journal may use the rule. A journal is the accounting record used to group transactions for a particular bank or cash account; it is not the bank account itself.<br><br><strong>What to choose</strong><br>Choose the journal linked to the account whose statement transactions this rule should check. For example, choose “Meezan Bank - PKR” for rules that only apply to that account. Leave this field blank if the rule should check transactions from every bank or cash journal.<br><br><strong>What effect it has</strong><br>Selecting a journal limits this rule to reconciliations for that journal. Leaving it blank makes the rule available to all bank and cash journals. Reconciliation means matching transactions on a bank statement with transactions recorded in the system.', 'This field controls where the rule runs; the conditions below control which transactions it matches.'))
                ->relationship(
                    'journal',
                    'name',
                    modifyQueryUsing: fn (Builder $query): Builder => $query
                        ->where('company_id', current_company_id())
                        ->whereIn('type', ['bank', 'cash']),
                )->searchable()->preload()->placeholder('All bank and cash journals')->nullable()
                ->live(),
            Select::make('action')->label(self::labelWithGuide('When a match is found', 'When a match is found', '<strong>What it means</strong><br>This decides whether a match found by the system needs a person to approve it.<br><br><strong>What to choose</strong><br><strong>Suggest for review:</strong> creates a proposed match for a person to check and confirm.<br><br><strong>Confirm automatically:</strong> confirms a unique, strong match without waiting for approval. Weaker matches are still proposed for review.<br><br><strong>What effect it has</strong><br>Automatic confirmation can complete matching faster, but it should be used only for rules you trust. A match links a bank-statement transaction to the corresponding accounting entry.', 'Start with “Suggest for review”. Use automatic confirmation only after reviewing this rule’s results.'))
                ->options(['propose' => 'Suggest for review', 'auto_confirm' => 'Confirm automatically'])->default('propose')->required()
                ->live(),
            Select::make('automation_safety')->label(self::labelWithGuide('Automatic confirmation level', 'Automatic confirmation level', '<strong>What it means</strong><br>This sets how much matching evidence is required before the system may confirm a match automatically. Evidence can include the amount, reference, customer or vendor, and transaction date.<br><br><strong>What to choose</strong><br><strong>Balanced:</strong> requires strong evidence and is the recommended starting point.<br><br><strong>Strict:</strong> requires a higher evidence score, so fewer matches can be confirmed automatically.<br><br><strong>What effect it has</strong><br>This setting matters only when “When a match is found” is set to “Confirm automatically”. Matches below the required score are proposed for review.', 'Choose Strict when a wrong automatic match would be especially costly.'))
                ->options([
                    'balanced' => 'Balanced (recommended)',
                    'strict'   => 'Strict',
                ])->default('balanced')->required()
                ->live(),
            Repeater::make('condition_rules')->label(self::labelWithGuide('Only match transactions when…', 'Matching conditions', '<strong>What it means</strong><br>A condition is a test that a bank-statement transaction must pass before this rule can apply.<br><br><strong>What to choose</strong><br>Add only the transaction details that reliably identify this type of transaction. “Reference contains” ignores letter case; transaction code and customer or vendor must match exactly.<br><br><strong>What effect it has</strong><br>Every condition must match. Adding conditions narrows the rule. With no conditions, every transaction in the selected journal and date range qualifies for this rule.', 'Use the fewest conditions that identify the transaction safely.'))->schema([
                Select::make('field')->label(self::labelWithGuide('Check this detail', 'Transaction detail', '<strong>What it means</strong><br>This is the part of the imported bank transaction that the rule checks.<br><br><strong>What to choose</strong><br><strong>Bank reference:</strong> text supplied with the transaction, such as FEE or POS.<br><br><strong>Transaction code:</strong> the bank’s exact category or code.<br><br><strong>Customer or vendor:</strong> the party already assigned to the transaction.<br><br><strong>Amount:</strong> the transaction amount as imported from the statement.<br><br><strong>What effect it has</strong><br>The selected detail is compared with the value entered beside it.', 'Each transaction detail can be used only once in a rule.'))
                    ->options([
                        'reference_contains' => 'Bank reference contains',
                        'transaction_code'   => 'Transaction code is',
                        'partner_id'         => 'Customer or vendor is',
                        'amount_min'         => 'Amount is at least',
                        'amount_max'         => 'Amount is at most',
                    ])->required()->live(),
                Select::make('partner_value')->label('Customer or vendor')->options(fn (): array => Partner::query()->where('company_id', current_company_id())->orderBy('name')->pluck('name', 'id')->all())->searchable()->visible(fn (Get $get): bool => $get('field') === 'partner_id')->required(fn (Get $get): bool => $get('field') === 'partner_id'),
                TextInput::make('value')->label(fn (Get $get): string => match ($get('field')) {
                    'reference_contains'       => 'Text to look for',
                    'transaction_code'         => 'Exact code',
                    'amount_min', 'amount_max' => 'Amount limit',
                    default                    => 'Value',
                })->required(fn (Get $get): bool => $get('field') !== 'partner_id')->visible(fn (Get $get): bool => $get('field') !== 'partner_id')->live()
                    ->numeric(fn (Get $get): bool => in_array($get('field'), ['amount_min', 'amount_max'], true))
                    ->placeholder(fn (Get $get): string => in_array($get('field'), ['amount_min', 'amount_max'], true) ? 'Example: 500.00' : 'Example: POS or FEE'),
            ])->columns(2)->defaultItems(0)->addActionLabel('Add another condition')->reorderable(false),
            Section::make('When this rule applies')
                ->description('Leave either date blank to keep the rule open-ended.')
                ->schema([
                    DatePicker::make('active_from')->label(self::labelWithGuide('Active from', 'Active from', '<strong>What it means</strong><br>The earliest transaction date on which this rule may apply.<br><br><strong>What to choose</strong><br>Choose the first date covered by the rule, or leave it blank if there is no starting limit.<br><br><strong>What effect it has</strong><br>Transactions dated before this day will not use the rule. The selected day is included.', 'The system normally uses the bank transaction date; if it is unavailable, it uses the statement or reconciliation period date.'))->live(),
                    DatePicker::make('active_until')->label(self::labelWithGuide('Active until', 'Active until', '<strong>What it means</strong><br>The last transaction date on which this rule may apply.<br><br><strong>What to choose</strong><br>Choose an end date for a temporary rule, or leave it blank if the rule should have no expiry date.<br><br><strong>What effect it has</strong><br>Transactions dated after this day will not use the rule. The selected day is included.', 'This date cannot be earlier than Active from.'))->live()->afterOrEqual('active_from'),
                ])->columns(2),
            Toggle::make('active')->label(self::labelWithGuide('Use this rule', 'Use this rule', '<strong>What it means</strong><br>This controls whether the matching system may use the rule.<br><br><strong>What to choose</strong><br>Turn it on when the rule is ready. Turn it off to keep the rule without using it for new matches.<br><br><strong>What effect it has</strong><br>Turning it off stops future use of the rule. It does not remove matches that were already proposed or confirmed.', 'Keep a new rule off until its journal, conditions, action, and dates are correct.'))->default(true)
                ->live(),
        ])->columns(2);
    }

    private static function labelWithGuide(string $label, string $title, string $content, ?string $learnMore = null): HtmlString
    {
        return new HtmlString(e($label).' '.view('components.guide-tooltip', compact('title', 'content', 'learnMore'))->render());
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->searchable()->sortable(),
            TextColumn::make('journal.name')->label('Applies to')->placeholder('All bank and cash journals')->searchable(),
            TextColumn::make('priority')->label('Order')->sortable()->tooltip('Rules run from top to bottom. Drag rows to change the order.'),
            TextColumn::make('action')->badge()->formatStateUsing(fn (string $state): string => match ($state) {
                'propose'      => 'Suggest for review',
                'auto_confirm' => 'Confirm automatically',
                default        => $state,
            }),
            TextColumn::make('version')->sortable(),
            IconColumn::make('active')->boolean(),
        ])->reorderable('priority')->reorderRecordsTriggerAction(fn (Action $action): Action => $action
            ->label('Arrange rules')
            ->tooltip('Enable this, then drag rules into the order they should run.'))
            ->afterReordering(function (): void {
                Notification::make()
                    ->success()
                    ->title('Rule order saved')
                    ->body('Matching will use the new top-to-bottom order.')
                    ->send();
            })
            ->recordActions([
                EditAction::make(),
            ])->headerActions([CreateAction::make()])->defaultSort('priority');
    }

    public static function getPages(): array
    {
        return [
            'index'  => ListReconciliationRules::route('/'),
            'create' => CreateReconciliationRule::route('/create'),
            'edit'   => EditReconciliationRule::route('/{record}/edit'),
        ];
    }
}
