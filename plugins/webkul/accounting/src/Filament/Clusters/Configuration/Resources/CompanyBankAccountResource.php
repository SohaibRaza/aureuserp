<?php

namespace Webkul\Accounting\Filament\Clusters\Configuration\Resources;

use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Webkul\Accounting\Filament\Clusters\Configuration;
use Webkul\Accounting\Filament\Clusters\Configuration\Resources\CompanyBankAccountResource\Pages\ManageCompanyBankAccounts;
use Webkul\Accounting\Models\BankAccount;
use Webkul\Partner\Filament\Resources\BankResource;

class CompanyBankAccountResource extends Resource
{
    protected static ?string $model = BankAccount::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-building-library';

    protected static ?string $cluster = Configuration::class;

    protected static ?int $navigationSort = 1;

    protected static ?string $slug = 'bank-accounts';

    public static function getModelLabel(): string
    {
        return __('accounting::filament/clusters/configurations/resources/company-bank-account.model-label');
    }

    public static function getNavigationLabel(): string
    {
        return __('accounting::filament/clusters/configurations/resources/company-bank-account.navigation.title');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('accounting::filament/clusters/configurations/resources/company-bank-account.navigation.group');
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('company_id', current_company_id());
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('account_number')
                ->label(__('accounting::filament/clusters/configurations/resources/company-bank-account.form.account-number'))
                ->required()
                ->unique(ignoreRecord: true)
                ->maxLength(255),
            Select::make('bank_id')
                ->label(__('accounting::filament/clusters/configurations/resources/company-bank-account.form.bank'))
                ->relationship('bank', 'name')
                ->required()
                ->searchable()
                ->preload()
                ->createOptionForm(fn (Schema $schema) => BankResource::form($schema)),
            Select::make('currency_id')
                ->label(__('accounting::filament/clusters/configurations/resources/company-bank-account.form.currency'))
                ->relationship('currency', 'name', modifyQueryUsing: fn (Builder $query) => $query->where('active', true))
                ->default(fn () => current_company()?->currency_id)
                ->required()
                ->searchable()
                ->preload(),
            Select::make('source_type')
                ->label(__('accounting::filament/clusters/configurations/resources/company-bank-account.form.source-type'))
                ->options([
                    'manual'    => 'Manual entry',
                    'csv'       => 'File import',
                    'api'       => 'Bank API',
                    'bank_feed' => 'Bank feed',
                ])
                ->default('manual')
                ->required(),
            TextInput::make('source_identifier')
                ->label(__('accounting::filament/clusters/configurations/resources/company-bank-account.form.source-identifier'))
                ->helperText(__('accounting::filament/clusters/configurations/resources/company-bank-account.form.source-identifier-help'))
                ->maxLength(255),
            Toggle::make('is_active')
                ->label(__('accounting::filament/clusters/configurations/resources/company-bank-account.form.active'))
                ->default(true),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('account_number')
                    ->label(__('accounting::filament/clusters/configurations/resources/company-bank-account.table.account-number'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('bank.name')
                    ->label(__('accounting::filament/clusters/configurations/resources/company-bank-account.table.bank'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('currency.name')
                    ->label(__('accounting::filament/clusters/configurations/resources/company-bank-account.table.currency')),
                TextColumn::make('journals.name')
                    ->label(__('accounting::filament/clusters/configurations/resources/company-bank-account.table.journals'))
                    ->placeholder('Not linked'),
                IconColumn::make('is_active')
                    ->label(__('accounting::filament/clusters/configurations/resources/company-bank-account.table.active'))
                    ->boolean(),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->defaultSort('account_number');
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageCompanyBankAccounts::route('/'),
        ];
    }
}
