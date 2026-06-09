<div>
    <h3 class="text-base font-semibold leading-6 text-gray-950 dark:text-white">{{ $heading }}</h3>
    @if (filled($description ?? null))
        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ $description }}</p>
    @endif
</div>
