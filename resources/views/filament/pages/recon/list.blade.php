<x-filament-panels::page>
    <div class="space-y-4">
        <div class="rounded-xl border border-primary-200 bg-primary-50 p-4 text-sm text-primary-900 dark:border-primary-500/30 dark:bg-primary-500/10 dark:text-primary-100" role="note">
            <div class="font-semibold">Bank reconciliation control centre</div>
            <p class="mt-1">Open a period to import evidence, review matches, resolve exceptions, and complete the close. Completed periods remain available as immutable reports.</p>
        </div>
        {{ $this->table }}
    </div>
</x-filament-panels::page>
