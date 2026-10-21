<?php

namespace Shafique\Logistics\Filament\Resources\TripResource\Pages;

use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Shafique\Logistics\Filament\Resources\TripResource;
use Shafique\Logistics\Services\OutboundTripWorkflow;
use Shafique\Logistics\Services\TripWorkspace;
use Webkul\TableViews\Filament\Components\PresetView;
use Webkul\TableViews\Filament\Concerns\HasTableViews;

class ListTrips extends ListRecords
{
    use HasTableViews;

    protected static string $resource = TripResource::class;

    #[Locked]
    public int $companyId;

    public function mount(): void
    {
        $this->companyId = app(TripWorkspace::class)->companyId();
        parent::mount();
    }

    public function getPresetTableViews(): array
    {
        return ['all' => PresetView::make(__('logistics::filament/resources/trip.navigation.title'))->favorite()->setAsDefault()];
    }

    protected function getHeaderActions(): array
    {
        return [Action::make('createTrip')->label(__('logistics::filament/resources/trip.actions.create'))->schema([
            Select::make('mode')->label(__('logistics::filament/resources/trip.fields.mode'))->options(TripResource::modes())->required(),
            Select::make('source_location_id')->label(__('logistics::filament/resources/trip.fields.origin'))->options(fn () => app(TripWorkspace::class)->locations($this->companyId))->searchable()->required(),
            TextInput::make('vehicle_reference')->label(__('logistics::filament/resources/trip.fields.vehicle'))->maxLength(120),
            Hidden::make('request_key')->default(fn () => (string) Str::uuid())->required()->uuid(),
        ])->visible(fn () => auth()->user()?->can('create_logistics_trip'))->action(function (array $data): void {
            $actor = app(TripWorkspace::class)->authorize('create_logistics_trip', $this->companyId);
            $trip = app(OutboundTripWorkflow::class)->createTrip($actor, $this->companyId, $data['request_key'], $data['mode'], (int) $data['source_location_id'], $data['vehicle_reference'] ?? null);
            $this->redirect(TripResource::getUrl('view', ['record' => $trip]));
        })];
    }
}
