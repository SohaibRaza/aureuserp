@props(['title', 'content', 'learnMore' => null])

<span x-data="{ guideOpen: false }" class="relative inline-flex align-middle">
    <button
        type="button"
        class="ml-1 inline-flex h-4 w-4 items-center justify-center rounded-full bg-gray-200 text-[10px] font-bold text-gray-700 outline-none transition hover:bg-primary-100 focus-visible:ring-2 focus-visible:ring-primary-500 dark:bg-gray-700 dark:text-gray-200 dark:hover:bg-primary-900"
        aria-label="{{ $title }} guide"
        :aria-expanded="guideOpen.toString()"
        x-tooltip="{ content: @js($content), theme: $store.theme, allowHTML: true }"
        @click="guideOpen = ! guideOpen"
        @keydown.enter.prevent="guideOpen = ! guideOpen"
        @keydown.space.prevent="guideOpen = ! guideOpen"
    >
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="h-3.5 w-3.5" aria-hidden="true">
            <path fill-rule="evenodd" d="M18 10a8 8 0 1 1-16 0 8 8 0 0 1 16 0ZM9.25 8.5a.75.75 0 0 1 1.5 0v4.75a.75.75 0 0 1-1.5 0V8.5Zm.75-3a.875.875 0 1 0 0 1.75.875.875 0 0 0 0-1.75Z" clip-rule="evenodd" />
        </svg>
    </button>

    @if ($learnMore)
        <div
            x-cloak
            x-show="guideOpen"
            x-transition.origin.top.left
            @click.outside="guideOpen = false"
            class="absolute left-0 top-6 z-50 w-80 rounded-lg border border-gray-200 bg-white p-4 text-sm text-gray-700 shadow-xl dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200"
            role="dialog"
            aria-label="{{ $title }} details"
        >
            <div class="mb-2 font-semibold text-gray-950 dark:text-white">{{ $title }}</div>
            <div class="leading-5">{{ $learnMore }}</div>
            <button type="button" class="mt-3 text-xs font-medium text-primary-600 hover:underline dark:text-primary-400" @click="guideOpen = false">Close</button>
        </div>
    @endif
</span>
