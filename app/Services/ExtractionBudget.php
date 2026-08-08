<?php

namespace App\Services;

/**
 * A wall-clock budget for one meta-extraction request.
 *
 * The endpoint is synchronous and user-facing, so every network call it makes — the
 * scrape, an optional browser re-scrape, and the AI healing agent — has to draw from a
 * single ceiling rather than each enforcing its own timeout. Callers ask for the
 * remaining time and pass it down as a per-call timeout.
 */
class ExtractionBudget
{
    protected float $startedAt;

    public function __construct(
        public readonly int $totalSeconds,
    ) {
        $this->startedAt = microtime(true);
    }

    public static function start(?int $totalSeconds = null): self
    {
        return new self($totalSeconds ?? (int) config('price_buddy.meta_extraction.budget_seconds', 25));
    }

    public function elapsedSeconds(): float
    {
        return microtime(true) - $this->startedAt;
    }

    public function remainingSeconds(): float
    {
        return max(0.0, $this->totalSeconds - $this->elapsedSeconds());
    }

    /**
     * Remaining budget as a whole number of seconds, for the timeout arguments of HTTP
     * clients that only take integers. Rounded down so the budget is never overspent,
     * with a floor of 1 — a 0 timeout means "no timeout" to Guzzle, which is the exact
     * failure mode this class exists to prevent.
     */
    public function remainingSecondsForTimeout(): int
    {
        return max(1, (int) floor($this->remainingSeconds()));
    }

    /**
     * Whether at least this many seconds remain. Used to decide whether an expensive
     * step can plausibly finish, rather than starting one that will only be cut off.
     */
    public function hasAtLeast(float $seconds): bool
    {
        return $this->remainingSeconds() >= $seconds;
    }

    public function isExhausted(): bool
    {
        return $this->remainingSeconds() <= 0.0;
    }
}
