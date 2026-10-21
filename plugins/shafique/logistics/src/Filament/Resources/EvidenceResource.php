<?php

namespace Shafique\Logistics\Filament\Resources;

use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Shafique\Logistics\Filament\Resources\EvidenceResource\Pages\ListEvidence;
use Shafique\Logistics\Models\Evidence;
use Webkul\PluginManager\Models\Plugin;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;
use Webkul\Support\Services\CompanyContext;

class EvidenceResource extends Resource
{
    protected static ?string $slug = 'logistics/evidence';

    protected static ?string $model = Evidence::class;

    protected static bool $isGloballySearchable = false;

    public static function canViewAny(): bool
    {
        $user = auth()->user();
        $companyId = app(CompanyContext::class)->currentId();

        return $user instanceof User
            && $user->can('view_any_logistics_evidence')
            && static::pluginIsInstalled()
            && $companyId !== null
            && static::userCanAccessCompany($user, $companyId);
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user = auth()->user();
        $companyId = app(CompanyContext::class)->currentId();

        if (! $user instanceof User || ! static::pluginIsInstalled() || $companyId === null || ! static::userCanAccessCompany($user, $companyId)) {
            return $query->whereRaw('1 = 0');
        }

        return $query
            ->select(['id', 'company_id', 'original_name', 'purpose', 'detected_mime_type', 'byte_size', 'accepted_at', 'retain_until'])
            ->where('company_id', $companyId);
    }

    public static function getNavigationLabel(): string
    {
        return __('logistics::filament/resources/evidence.navigation.title');
    }

    public static function getNavigationGroup(): string|\UnitEnum
    {
        return __('logistics::settings.navigation');
    }

    public static function getModelLabel(): string
    {
        return __('logistics::filament/resources/evidence.navigation.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('logistics::filament/resources/evidence.navigation.title');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('original_name')
                    ->label(__('logistics::filament/resources/evidence.table.columns.original-name'))
                    ->searchable()
                    ->limit(60),
                TextColumn::make('purpose')
                    ->label(__('logistics::filament/resources/evidence.table.columns.purpose'))
                    ->formatStateUsing(fn (string $state): string => __("logistics::filament/resources/evidence.purposes.{$state}"))
                    ->sortable(),
                TextColumn::make('detected_mime_type')
                    ->label(__('logistics::filament/resources/evidence.table.columns.file-type')),
                TextColumn::make('byte_size')
                    ->label(__('logistics::filament/resources/evidence.table.columns.file-size'))
                    ->numeric(),
                TextColumn::make('accepted_at')
                    ->label(__('logistics::filament/resources/evidence.table.columns.uploaded-at'))
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('retain_until')
                    ->label(__('logistics::filament/resources/evidence.table.columns.retain-until'))
                    ->dateTime()
                    ->sortable(),
            ])
            ->defaultSort('accepted_at', 'desc')
            ->emptyStateHeading(__('logistics::filament/resources/evidence.table.empty-state.heading'))
            ->emptyStateDescription(__('logistics::filament/resources/evidence.table.empty-state.description'))
            ->recordActions([])
            ->toolbarActions([]);
    }

    public static function getPages(): array
    {
        return ['index' => ListEvidence::route('/')];
    }

    public static function pluginIsInstalled(): bool
    {
        return Plugin::query()->where('name', 'logistics')->where('is_installed', true)->exists();
    }

    public static function userCanAccessCompany(User $user, int $companyId): bool
    {
        $user = User::withoutGlobalScopes()->find($user->id);
        if (! $user || ! $user->is_active || $user->trashed()) {
            return false;
        }
        if ($user->can('bypass_company_scope')) {
            return Company::query()->whereKey($companyId)->exists();
        }

        return $user->allowedCompanies()->whereKey($companyId)->exists();
    }
}
