<?php

namespace Shafique\Logistics\Filament\Resources\ModuleSettingResource\Pages;

use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Pages\Page;
use Livewire\Attributes\Locked;
use Shafique\Logistics\Filament\Resources\ModuleSettingResource;
use Shafique\Logistics\Services\OperationalControl;
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
        ];
    }
}
