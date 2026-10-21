<?php

namespace Shafique\Logistics\Filament\Resources\TripResource\Pages;

use Closure;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Shafique\Logistics\Filament\Resources\TripResource;
use Shafique\Logistics\Models\Evidence;
use Shafique\Logistics\Services\ApprovalWorkflow;
use Shafique\Logistics\Services\DispatchReadiness;
use Shafique\Logistics\Services\OutboundTripWorkflow;
use Shafique\Logistics\Services\TripWorkspace;
use Webkul\Partner\Models\Partner;
use Webkul\Security\Models\User;
use Webkul\Support\Models\UOM;

class ViewTrip extends ViewRecord
{
    protected static string $resource = TripResource::class;

    #[Locked]
    public int $companyId;

    #[Locked]
    public int $observedRevision;

    public function mount(int|string $record): void
    {
        $this->companyId = app(TripWorkspace::class)->companyId();
        parent::mount($record);
        $this->observedRevision = (int) $this->getRecord()->revision;
    }

    public function getSubheading(): ?string
    {
        return __('logistics::filament/resources/trip.steps');
    }

    protected function getHeaderActions(): array
    {
        $trip = $this->getRecord();

        return [
            $this->command('releaseAllocation', 'allocate_logistics_demand', [
                Select::make('allocation_id')->label($this->label('demand'))->options(fn () => $this->getRecord()->allocations()->where('state', 'active')->where('loaded_quantity', 0)->with(['product', 'orderLine.order'])->get()->mapWithKeys(fn ($allocation) => [$allocation->id => $allocation->orderLine->order->name.' — '.$allocation->product->name.' — '.$allocation->stock_quantity]))->required(),
                Textarea::make('reason')->label($this->label('recovery_reason'))->required()->maxLength(2000),
            ], function (User $actor, array $data): void {
                app(OutboundTripWorkflow::class)->releaseAllocation($actor, $this->getRecord()->id, (int) $data['expected_revision'], $data['request_key'], (int) $data['allocation_id'], $data['reason']);
            })->modalDescription(__('logistics::filament/resources/trip.recovery.claim_help'))
                ->visible(fn () => $this->allowed('allocate_logistics_demand') && $trip->state !== 'cancelled' && $trip->allocations()->where('state', 'active')->where('loaded_quantity', 0)->exists()),
            $this->command('unload', 'record_logistics_load', [
                Toggle::make('physically_unloaded')->label(__('logistics::filament/resources/trip.recovery.confirm_unloaded'))->accepted(),
                Textarea::make('reason')->label($this->label('recovery_reason'))->required()->maxLength(2000),
            ], function (User $actor, array $data): void {
                app(OutboundTripWorkflow::class)->unload($actor, $this->getRecord()->id, (int) $data['expected_revision'], $data['request_key'], $data['reason'], (bool) $data['physically_unloaded']);
            })->requiresConfirmation()->modalDescription(__('logistics::filament/resources/trip.recovery.unload_help'))
                ->visible(fn () => $this->allowed('record_logistics_load') && in_array($trip->state, ['loading', 'verified'], true)),
            $this->command('cancelTrip', 'manage_logistics_trip', [
                Textarea::make('reason')->label($this->label('recovery_reason'))->required()->maxLength(2000),
            ], function (User $actor, array $data): void {
                app(OutboundTripWorkflow::class)->cancelTrip($actor, $this->getRecord()->id, (int) $data['expected_revision'], $data['request_key'], $data['reason']);
            })->requiresConfirmation()->modalDescription(__('logistics::filament/resources/trip.recovery.cancel_help'))
                ->visible(fn () => $this->allowed('manage_logistics_trip') && $trip->state === 'planning' && ! $trip->load_revision_id && ! $trip->releaseBatches()->exists()),
            $this->command('addStop', 'manage_logistics_trip', [
                TextInput::make('sequence')->label($this->label('sequence'))->integer()->minValue(1)->maxValue(100)->required(),
                Select::make('commitment_id')->label($this->label('destination_order'))->options(fn () => app(TripWorkspace::class)->demandOptions($this->companyId))->getSearchResultsUsing(fn (string $search) => app(TripWorkspace::class)->demandOptions($this->companyId, $search))->searchable()->required(),
            ], function (User $actor, array $data): void {
                $snapshot = app(TripWorkspace::class)->commitment($this->companyId, (int) $data['commitment_id']);
                app(OutboundTripWorkflow::class)->addStop($actor, $this->getRecord()->id, (int) $data['expected_revision'], $data['request_key'], (int) $data['sequence'], (int) ($snapshot->data['shipping_partner_id'] ?? 0), $snapshot->data['shipping_address'] ?? []);
            })->visible(fn () => $this->allowed('manage_logistics_trip') && $trip->state === 'planning'),
            $this->command('allocateDemand', 'allocate_logistics_demand', [
                Select::make('stop_id')->label($this->label('stop'))->options(fn () => $this->stopOptions())->required(),
                Select::make('commitment_id')->label($this->label('demand'))->options(fn () => app(TripWorkspace::class)->demandOptions($this->companyId))->getSearchResultsUsing(fn (string $search) => app(TripWorkspace::class)->demandOptions($this->companyId, $search))->searchable()->required(),
                Select::make('source_location_id')->label($this->label('origin'))->options(fn () => app(TripWorkspace::class)->locations($this->companyId))->default($trip->source_location_id)->required(),
                TextInput::make('stock_quantity')->label($this->label('stock_quantity'))->required()->regex('/^(?:0|[1-9]\d{0,10})(?:\.\d{1,4})?$/'),
            ], function (User $actor, array $data): void {
                $snapshot = app(TripWorkspace::class)->commitment($this->companyId, (int) $data['commitment_id']);
                app(OutboundTripWorkflow::class)->allocateDemand($actor, $this->getRecord()->id, (int) $data['expected_revision'], $data['request_key'], (int) $data['stop_id'], (int) $snapshot->order_line_id, $snapshot->id, (int) $data['source_location_id'], (string) $data['stock_quantity']);
            })->visible(fn () => $this->allowed('allocate_logistics_demand') && in_array($trip->state, ['planning', 'released'], true)),
            $this->command('recordLoad', 'record_logistics_load', [
                Select::make('source_location_id')->label($this->label('origin'))->options(fn () => app(TripWorkspace::class)->locations($this->companyId))->default($trip->source_location_id)->required()->live()
                    ->afterStateUpdated(fn ($state, Set $set) => $set('items', app(TripWorkspace::class)->loadRows($this->getRecord(), (int) $state))),
                Repeater::make('items')->label(__('logistics::filament/resources/trip.sections.load'))->helperText(__('logistics::filament/resources/trip.load_help'))
                    ->default(fn () => app(TripWorkspace::class)->loadRows($this->getRecord()))
                    ->addable(false)->deletable(false)->reorderable(false)->minItems(1)->required()->schema([
                        Hidden::make('allocation_id')->required()->rules(['integer']), Hidden::make('move_id')->required()->rules(['integer']), Hidden::make('move_line_id')->required()->rules(['integer']),
                        TextInput::make('description')->label($this->label('product'))->readOnly()->dehydrated(false),
                        TextInput::make('reserved')->label($this->label('reserved'))->readOnly()->dehydrated(false),
                        TextInput::make('stock_quantity')->label($this->label('actual'))->required()->regex('/^(?:0|[1-9]\d{0,10})(?:\.\d{1,4})?$/'),
                    ])->columns(3),
            ], function (User $actor, array $data): void {
                app(OutboundTripWorkflow::class)->recordLoad($actor, $this->getRecord()->id, (int) $data['expected_revision'], $data['request_key'], $data['items'], (int) $data['source_location_id']);
            })->visible(fn () => $this->allowed('record_logistics_load') && in_array($trip->state, ['planning', 'loading', 'verified', 'released'], true)),
            $this->command('recordChecks', 'record_logistics_load', $this->checksSchema(), function (User $actor, array $data): void {
                $checks = $data;
                unset($checks['request_key'], $checks['expected_revision']);
                app(DispatchReadiness::class)->recordChecks($actor, $this->getRecord()->id, (int) $data['expected_revision'], $data['request_key'], $checks);
            })->visible(fn () => $this->allowed('record_logistics_load') && $trip->state === 'loading'),
            $this->command('verifyLoad', 'verify_logistics_load', $this->approvalSchema(true), function (User $actor, array $data): void {
                DB::transaction(function () use ($actor, $data): void {
                    $trip = $this->getRecord();
                    $load = $trip->loadRevision()->with('items')->firstOrFail();
                    $requester = User::withoutGlobalScopes()->findOrFail($load->recorded_by);
                    $quantity = number_format($load->items->sum('stock_quantity'), 4, '.', '');
                    $approval = app(ApprovalWorkflow::class)->approve($requester, $actor, $this->companyId, $data['request_key'].'.approval', 'load.verify', 'load_revision', $load->id, $load->revision, ['quantity' => $quantity], Carbon::parse($data['valid_until']), $data['reason']);
                    app(OutboundTripWorkflow::class)->verifyLoad($actor, $trip->id, (int) $data['expected_revision'], $data['request_key'], $approval->id, isset($data['evidence_id']) ? (int) $data['evidence_id'] : null);
                });
            })->visible(fn () => $this->allowed('verify_logistics_load') && $trip->state === 'loading'),
            $this->command('releaseStock', 'release_logistics_trip', $this->approvalSchema(false), function (User $actor, array $data): void {
                DB::transaction(function () use ($actor, $data): void {
                    $trip = $this->getRecord();
                    $load = $trip->loadRevision()->with('items')->firstOrFail();
                    $requester = User::withoutGlobalScopes()->findOrFail($load->recorded_by);
                    $quantity = number_format($load->items->sum('stock_quantity'), 4, '.', '');
                    $approval = app(ApprovalWorkflow::class)->approve($requester, $actor, $this->companyId, $data['request_key'].'.approval', 'release.stock', 'trip', $trip->id, (int) $data['expected_revision'], ['quantity' => $quantity], Carbon::parse($data['valid_until']), $data['reason']);
                    app(OutboundTripWorkflow::class)->release($actor, $trip->id, (int) $data['expected_revision'], $data['request_key'], $approval->id);
                });
            })->requiresConfirmation()->modalDescription(__('logistics::filament/resources/trip.release_help'))
                ->visible(fn () => $this->allowed('release_logistics_trip') && $trip->state === 'verified'),
            $this->command('recordReceipt', 'record_logistics_receipt', [
                Select::make('stop_id')->label($this->label('stop'))->options(fn () => $this->stopOptions())->required(),
                TextInput::make('reporter_name')->label($this->label('reporter'))->required()->maxLength(120),
                DateTimePicker::make('occurred_at')->label($this->label('reported_time'))->required()->maxDate(now()),
                Select::make('intent')->label($this->label('report_type'))->options(collect(['incremental', 'cumulative', 'correction', 'unclear'])->mapWithKeys(fn ($value) => [$value => __('logistics::filament/resources/trip.intents.'.$value)]))->required(),
                TextInput::make('quantity')->label($this->label('quantity'))->numeric()->minValue(0),
                Select::make('unit_id')->label($this->label('unit'))->options(fn () => UOM::query()->pluck('name', 'id'))->searchable(),
                Select::make('evidence_id')->label($this->label('evidence'))->options(fn () => $this->evidenceOptions(['delivery_receipt', 'proof_of_delivery']))->searchable(),
                Textarea::make('notes')->label($this->label('notes'))->required()->maxLength(2000),
            ], function (User $actor, array $data): void {
                app(OutboundTripWorkflow::class)->recordReceiptObservation($actor, $this->getRecord()->id, (int) $data['stop_id'], $data['request_key'], $data['intent'], isset($data['quantity']) ? (string) $data['quantity'] : null, isset($data['unit_id']) ? (int) $data['unit_id'] : null, $data['notes'], isset($data['evidence_id']) ? (int) $data['evidence_id'] : null, $data['reporter_name'], $data['occurred_at']);
            })->modalDescription(__('logistics::filament/resources/trip.receipt_help'))
                ->visible(fn () => $this->allowed('record_logistics_receipt') && $trip->releaseBatches()->exists()),
        ];
    }

    private function command(string $name, string $permission, array $schema, Closure $callback): Action
    {
        return Action::make($name)->label(__('logistics::filament/resources/trip.actions.'.$name))->schema([
            Hidden::make('request_key')->default(fn () => (string) Str::uuid())->required()->uuid(),
            Hidden::make('expected_revision')->default(fn () => $this->observedRevision)->rules(['integer'])->required(),
            ...$schema,
        ])->action(function (array $data) use ($permission, $callback): void {
            $actor = app(TripWorkspace::class)->authorize($permission, $this->companyId);
            abort_unless((int) $this->getRecord()->company_id === $this->companyId, 404);
            try {
                $callback($actor, $data);
            } catch (DomainException $exception) {
                throw ValidationException::withMessages(['trip' => $exception->getMessage()]);
            }
            $this->getRecord()->refresh();
            $this->observedRevision = (int) $this->getRecord()->revision;
            Notification::make()->success()->title(__('logistics::filament/resources/trip.saved'))->send();
        });
    }

    private function checksSchema(): array
    {
        return [
            DateTimePicker::make('loading_deadline')->label($this->label('deadline'))->required(),
            Select::make('provider_id')->label($this->label('provider'))->options(fn () => Partner::query()->where(fn ($query) => $query->where('company_id', $this->companyId)->orWhereNull('company_id'))->pluck('name', 'id'))->searchable()->required(),
            TextInput::make('driver_name')->label($this->label('driver'))->maxLength(120)->required(),
            TextInput::make('custodian_name')->label($this->label('custodian'))->maxLength(120)->required(),
            TextInput::make('vehicle_payload_kg')->label($this->label('payload'))->numeric()->minValue(0.0001)->required(),
            TextInput::make('loaded_gross_weight_kg')->label($this->label('gross_weight'))->numeric()->minValue(0.0001)->required(),
            TextInput::make('vehicle_volume_m3')->label($this->label('volume_limit'))->numeric()->minValue(0.0001),
            TextInput::make('loaded_volume_m3')->label($this->label('volume'))->numeric()->minValue(0.0001),
            Textarea::make('volume_not_required_reason')->label($this->label('volume_reason'))->maxLength(2000),
            Textarea::make('handling_confirmation')->label($this->label('handling'))->helperText(__('logistics::filament/resources/trip.handling_help'))->required()->maxLength(2000),
            Textarea::make('quality_release_reference')->label($this->label('quality'))->maxLength(2000),
        ];
    }

    private function approvalSchema(bool $withEvidence): array
    {
        return [
            DateTimePicker::make('valid_until')->label($this->label('approval_expiry'))->minDate(now())->required(),
            Textarea::make('reason')->label($this->label('approval_reason'))->required()->maxLength(1000),
            ...($withEvidence ? [Select::make('evidence_id')->label($this->label('evidence'))->options(fn () => $this->evidenceOptions(['load_verification', 'loading_photo']))->searchable()] : []),
        ];
    }

    private function stopOptions(): array
    {
        return $this->getRecord()->stops()->get()->mapWithKeys(fn ($stop) => [$stop->id => $stop->sequence.' — '.($stop->address_snapshot['name'] ?? '')])->all();
    }

    private function evidenceOptions(array $purposes): array
    {
        if (! auth()->user()?->can('view_logistics_evidence')) {
            return [];
        }

        return Evidence::query()->where('company_id', $this->companyId)->whereIn('purpose', $purposes)->latest('id')->limit(100)->pluck('original_name', 'id')->all();
    }

    private function label(string $key): string
    {
        return __('logistics::filament/resources/trip.fields.'.$key);
    }

    private function allowed(string $permission): bool
    {
        return auth()->user()?->can($permission) ?? false;
    }
}
