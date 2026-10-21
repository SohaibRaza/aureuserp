<?php

namespace Shafique\Logistics\Filament\Resources;

use Filament\Actions\ViewAction;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Shafique\Logistics\Filament\Resources\TripResource\Pages\ListTrips;
use Shafique\Logistics\Filament\Resources\TripResource\Pages\ViewTrip;
use Shafique\Logistics\Models\Trip;
use Shafique\Logistics\Services\TripWorkspace;

class TripResource extends Resource
{
    protected static ?string $slug = 'logistics/trips';

    protected static ?string $model = Trip::class;

    protected static bool $isGloballySearchable = false;

    public static function getNavigationGroup(): ?string
    {
        return __('logistics::settings.navigation');
    }

    public static function getModelLabel(): string
    {
        return __('logistics::filament/resources/trip.navigation.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('logistics::filament/resources/trip.navigation.title');
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('view_any_logistics_trip') ?? false;
    }

    public static function canView(Model $record): bool
    {
        return static::canViewAny() && (int) $record->company_id === app(TripWorkspace::class)->companyId();
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('company_id', app(TripWorkspace::class)->companyId())
            ->with(['sourceLocation', 'stops', 'allocations.product', 'allocations.orderLine.order', 'loadRevision.items.allocation.product', 'receipts', 'recoveryEvents.actor']);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('id')->label(__('logistics::filament/resources/trip.fields.reference'))->sortable(),
            TextColumn::make('mode')->label(__('logistics::filament/resources/trip.fields.mode'))->formatStateUsing(fn ($state) => __('logistics::filament/resources/trip.modes.'.$state)),
            TextColumn::make('vehicle_reference')->label(__('logistics::filament/resources/trip.fields.vehicle'))->searchable(),
            TextColumn::make('sourceLocation.full_name')->label(__('logistics::filament/resources/trip.fields.origin')),
            TextColumn::make('state')->label(__('logistics::filament/resources/trip.fields.status'))->badge()->formatStateUsing(fn ($state) => __('logistics::filament/resources/trip.states.'.$state)),
            TextColumn::make('created_at')->label(__('logistics::filament/resources/trip.fields.created'))->dateTime()->sortable(),
        ])->defaultSort('id', 'desc')->filters([
            SelectFilter::make('mode')->label(__('logistics::filament/resources/trip.fields.mode'))->options(static::modes()),
        ])->recordActions([ViewAction::make()])->toolbarActions([])
            ->emptyStateHeading(__('logistics::filament/resources/trip.empty.heading'))
            ->emptyStateDescription(__('logistics::filament/resources/trip.empty.description'));
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('logistics::filament/resources/trip.sections.trip'))->schema([
                TextEntry::make('mode')->label(__('logistics::filament/resources/trip.fields.mode'))->formatStateUsing(fn ($state) => __('logistics::filament/resources/trip.modes.'.$state)),
                TextEntry::make('vehicle_reference')->label(__('logistics::filament/resources/trip.fields.vehicle')),
                TextEntry::make('state')->label(__('logistics::filament/resources/trip.fields.status'))->formatStateUsing(fn ($state) => __('logistics::filament/resources/trip.states.'.$state)),
                TextEntry::make('sourceLocation.full_name')->label(__('logistics::filament/resources/trip.fields.origin')),
            ])->columns(2),
            Section::make(__('logistics::filament/resources/trip.sections.stops'))->schema([
                RepeatableEntry::make('stops')->hiddenLabel()->schema([
                    TextEntry::make('sequence')->label(__('logistics::filament/resources/trip.fields.sequence')),
                    TextEntry::make('address_snapshot.name')->label(__('logistics::filament/resources/trip.fields.recipient')),
                    TextEntry::make('delivery_address')->label(__('logistics::filament/resources/trip.fields.address'))->state(fn ($record) => implode(', ', array_filter(array_intersect_key($record->address_snapshot ?? [], array_flip(['street1', 'street2', 'city', 'zip']))))),
                ])->columns(3),
            ]),
            Section::make(__('logistics::filament/resources/trip.sections.allocations'))->schema([
                RepeatableEntry::make('allocations')->hiddenLabel()->schema([
                    TextEntry::make('orderLine.order.name')->label(__('logistics::filament/resources/trip.fields.order')),
                    TextEntry::make('product.name')->label(__('logistics::filament/resources/trip.fields.product')),
                    TextEntry::make('stock_quantity')->label(__('logistics::filament/resources/trip.fields.planned')),
                    TextEntry::make('released_quantity')->label(__('logistics::filament/resources/trip.fields.issued')),
                    TextEntry::make('state')->label(__('logistics::filament/resources/trip.fields.status'))->formatStateUsing(fn ($state) => __('logistics::filament/resources/trip.allocation_states.'.$state)),
                ])->columns(4),
            ]),
            Section::make(__('logistics::filament/resources/trip.sections.load'))->description(__('logistics::filament/resources/trip.load_help'))->schema([
                RepeatableEntry::make('loadRevision.items')->hiddenLabel()->schema([
                    TextEntry::make('allocation.product.name')->label(__('logistics::filament/resources/trip.fields.product')),
                    TextEntry::make('stock_quantity')->label(__('logistics::filament/resources/trip.fields.actual')),
                ])->columns(2),
            ]),
            Section::make(__('logistics::filament/resources/trip.recovery.history'))->schema([
                RepeatableEntry::make('recoveryEvents')->hiddenLabel()->schema([
                    TextEntry::make('action')->label(__('logistics::filament/resources/trip.recovery.action'))->formatStateUsing(fn ($state) => __('logistics::filament/resources/trip.recovery.events.'.$state)),
                    TextEntry::make('actor.name')->label(__('logistics::filament/resources/trip.recovery.actor')),
                    TextEntry::make('reason')->label(__('logistics::filament/resources/trip.fields.recovery_reason')),
                    TextEntry::make('recorded_at')->label(__('logistics::filament/resources/trip.fields.created'))->dateTime(),
                ])->columns(2),
            ]),
            Section::make(__('logistics::filament/resources/trip.sections.receipts'))->description(__('logistics::filament/resources/trip.receipt_help'))->schema([
                RepeatableEntry::make('receipts')->hiddenLabel()->schema([
                    TextEntry::make('reporter_name')->label(__('logistics::filament/resources/trip.fields.reporter')),
                    TextEntry::make('intent')->label(__('logistics::filament/resources/trip.fields.report_type'))->formatStateUsing(fn ($state) => __('logistics::filament/resources/trip.intents.'.$state)),
                    TextEntry::make('quantity')->label(__('logistics::filament/resources/trip.fields.quantity')),
                    TextEntry::make('notes')->label(__('logistics::filament/resources/trip.fields.notes')),
                ])->columns(4),
            ]),
        ]);
    }

    public static function modes(): array
    {
        return collect(['dedicated_vehicle', 'multi_stop'])->mapWithKeys(fn ($mode) => [$mode => __('logistics::filament/resources/trip.modes.'.$mode)])->all();
    }

    public static function getPages(): array
    {
        return ['index' => ListTrips::route('/'), 'view' => ViewTrip::route('/{record}')];
    }
}
