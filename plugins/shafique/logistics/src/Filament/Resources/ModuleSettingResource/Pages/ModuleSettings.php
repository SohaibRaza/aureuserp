<?php

namespace Shafique\Logistics\Filament\Resources\ModuleSettingResource\Pages;

use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Pages\Page;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Shafique\Logistics\Filament\Resources\ModuleSettingResource;
use Shafique\Logistics\Services\DispatchReadiness;
use Shafique\Logistics\Services\OperationalControl;
use Shafique\Logistics\Services\TripWorkspace;
use Webkul\Product\Models\Product;
use Webkul\Support\Models\Company;
use Webkul\Support\Services\CompanyContext;

class ModuleSettings extends Page
{
    protected static string $resource = ModuleSettingResource::class;

    protected string $view = 'logistics::module-settings';

    #[Locked]
    public int $companyId;

    public function mount(): void
    {
        abort_unless(ModuleSettingResource::canViewAny(), 403);
        $this->companyId = app(CompanyContext::class)->currentId() ?? abort(403);
        app(OperationalControl::class)->read(auth()->user(), $this->companyId);
    }

    protected function getViewData(): array
    {
        return [
            'acceptNewActivity' => app(OperationalControl::class)->read(auth()->user(), $this->companyId),
            'companyName'       => Company::query()->findOrFail($this->companyId)->name,
            'dispatchPolicy'    => app(DispatchReadiness::class)->policy($this->companyId),
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('changeActivity')
                ->label(__('logistics::settings.change'))
                ->visible(fn (): bool => auth()->user()?->can('update_logistics_module::setting') ?? false)
                ->schema([
                    Toggle::make('accept_new_activity')->label(__('logistics::settings.accept')),
                    Textarea::make('reason')->label(__('logistics::settings.reason'))->required()->maxLength(1000),
                ])
                ->fillForm(fn (): array => ['accept_new_activity' => app(OperationalControl::class)->read(auth()->user(), $this->companyId)])
                ->action(function (array $data): void {
                    app(OperationalControl::class)->change(auth()->user(), $this->companyId, (bool) $data['accept_new_activity'], $data['reason']);
                }),
            Action::make('approveDispatchPolicy')
                ->fillForm(function (): array {
                    $policy = app(DispatchReadiness::class)->policy($this->companyId);

                    return ['request_key' => (string) Str::uuid(), 'source_location_ids' => $policy?->source_location_ids ?? [], 'product_rules' => $policy?->product_rules ?? [], 'require_load_evidence' => $policy?->require_load_evidence ?? true, 'require_receipt_evidence' => $policy?->require_receipt_evidence ?? true];
                })
                ->label(__('logistics::filament/resources/trip.policy.title'))
                ->modalDescription(__('logistics::filament/resources/trip.policy.description'))
                ->visible(fn (): bool => auth()->user()?->can('configure_logistics_dispatch') ?? false)
                ->schema([
                    Hidden::make('request_key')->default(fn () => (string) Str::uuid())->required()->uuid(),
                    Select::make('source_location_ids')->label(__('logistics::filament/resources/trip.policy.sites'))->options(fn () => app(TripWorkspace::class)->locations($this->companyId))->multiple()->required(),
                    Repeater::make('product_rules')->label(__('logistics::filament/resources/trip.policy.products'))->minItems(1)->required()->schema([
                        Select::make('product_id')->label(__('logistics::filament/resources/trip.policy.product'))->options(fn () => Product::query()->where(fn ($query) => $query->where('company_id', $this->companyId)->orWhereNull('company_id'))->where('is_storable', true)->pluck('name', 'id'))->searchable()->required()->distinct(),
                        Select::make('quality_required')->label(__('logistics::filament/resources/trip.policy.quality'))->options([0 => __('logistics::filament/resources/trip.policy.no'), 1 => __('logistics::filament/resources/trip.policy.yes')])->required(),
                        Textarea::make('instructions')->label(__('logistics::filament/resources/trip.policy.instructions'))->required()->maxLength(2000),
                    ]),
                    Toggle::make('require_load_evidence')->label(__('logistics::filament/resources/trip.policy.load_evidence'))->default(true),
                    Toggle::make('require_receipt_evidence')->label(__('logistics::filament/resources/trip.policy.receipt_evidence'))->default(true),
                    Textarea::make('reason')->label(__('logistics::filament/resources/trip.policy.reason'))->required()->maxLength(1000),
                ])
                ->action(function (array $data): void {
                    $actor = app(TripWorkspace::class)->authorize('configure_logistics_dispatch', $this->companyId);
                    $key = $data['request_key'];
                    unset($data['request_key']);
                    app(DispatchReadiness::class)->approvePolicy($actor, $this->companyId, $key, $data);
                }),
        ];
    }
}
