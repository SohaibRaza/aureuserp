<x-filament-panels::page>
    <x-filament::section>
        <dl>
            <dt>{{ __('logistics::settings.company') }}</dt>
            <dd>{{ $companyName }} (#{{ $companyId }})</dd>
            <dt>{{ __('logistics::settings.accept') }}</dt>
            <dd>{{ __($acceptNewActivity ? 'logistics::settings.active' : 'logistics::settings.suspended') }}</dd>
        </dl>
        <p>{{ __('logistics::settings.scope') }}</p>
    </x-filament::section>
</x-filament-panels::page>
