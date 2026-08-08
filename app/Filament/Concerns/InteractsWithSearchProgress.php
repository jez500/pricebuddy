<?php

namespace App\Filament\Concerns;

use App\Services\SearchService;

/**
 * Shared polling state for the components that display a background search's progress
 * log (the create-via-search widget and the product source search page).
 *
 * The search itself runs in a queued job and reports progress through SearchService's
 * cache-backed log; these components only mirror that cache into component state while
 * the job is running.
 */
trait InteractsWithSearchProgress
{
    /**
     * @var array<int, array<string, mixed>>
     */
    public array $progressLog = [];

    public bool $showLog = false;

    /**
     * Timestamp of when the search job was started, or false when nothing is running.
     */
    public false|string $inProgress = false;

    /**
     * Timestamp of when the search job completed, or false when it has not.
     */
    public false|string $isComplete = false;

    /**
     * Called on poll from the frontend.
     *
     * A finished search has nothing left to report, so stop hitting the cache. The view
     * drops its wire:poll once $isComplete is set, but a poll already in flight can still
     * land after that.
     */
    public function refreshProgress(): void
    {
        if ($this->isComplete && ! $this->inProgress) {
            return;
        }

        $this->syncProgressFromService();
    }

    /**
     * Mirror the service's cached log and job flags into component state.
     */
    protected function syncProgressFromService(): void
    {
        $searchQuery = $this->resolveProgressSearchQuery();

        if (! $searchQuery) {
            return;
        }

        $service = $this->makeProgressSearchService($searchQuery);
        $log = $service->getLog();

        // Keep locally staged entries (eg "Preparing to search") until the job has
        // written its first entry, otherwise the panel flashes empty.
        if (! empty($log)) {
            $this->progressLog = $log;
        }

        $this->inProgress = $service->getInProgress() ?: false;
        $this->isComplete = $service->getIsComplete() ?: false;
    }

    /**
     * The query whose progress this component is tracking.
     */
    protected function resolveProgressSearchQuery(): ?string
    {
        return $this->searchQuery;
    }

    /**
     * Build the service used to read progress. Override to scope it, eg to a product source.
     */
    protected function makeProgressSearchService(string $searchQuery): SearchService
    {
        return SearchService::new($searchQuery);
    }
}
