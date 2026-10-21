<x-filament-panels::page>
    <x-filament::section>
        <dl>
            <dt>{{ __('logistics::settings.company') }}</dt>
            <dd>{{ $companyName }} (#{{ $companyId }})</dd>
            <dt>{{ __('logistics::settings.accept') }}</dt>
            <dd>{{ __($acceptNewActivity ? 'logistics::settings.active' : 'logistics::settings.suspended') }}</dd>
        </dl>
        <p>{{ __('logistics::settings.scope') }}</p>
        <p>{{ $dispatchPolicy ? __('logistics::filament/resources/trip.policy.configured', ['revision' => $dispatchPolicy->revision]) : __('logistics::filament/resources/trip.policy.not_configured') }}</p>
        <p>{{ __('logistics::filament/resources/trip.policy.roles') }}</p>
        @if (\Webkul\Security\Filament\Resources\RoleResource::canViewAny())
            <x-filament::link :href="\Webkul\Security\Filament\Resources\RoleResource::getUrl('index')">{{ __('logistics::settings.staff_roles') }}</x-filament::link>
        @endif
    </x-filament::section>
</x-filament-panels::page>
