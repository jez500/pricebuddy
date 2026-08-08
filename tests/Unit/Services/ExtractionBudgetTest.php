<?php

namespace Tests\Unit\Services;

use App\Models\Store;
use App\Services\Ai\HealingContext;
use App\Services\ExtractionBudget;
use RuntimeException;
use Tests\TestCase;

class ExtractionBudgetTest extends TestCase
{
    public function test_it_reports_the_time_it_has_left(): void
    {
        $budget = new ExtractionBudget(10);

        $this->assertGreaterThan(9.0, $budget->remainingSeconds());
        $this->assertLessThanOrEqual(10.0, $budget->remainingSeconds());
        $this->assertTrue($budget->hasAtLeast(5));
        $this->assertFalse($budget->hasAtLeast(11));
        $this->assertFalse($budget->isExhausted());
    }

    public function test_an_empty_budget_is_exhausted_and_never_negative(): void
    {
        $budget = new ExtractionBudget(0);

        $this->assertTrue($budget->isExhausted());
        $this->assertSame(0.0, $budget->remainingSeconds());
        $this->assertFalse($budget->hasAtLeast(1));
    }

    public function test_an_exhausted_budget_has_no_safe_timeout(): void
    {
        // Not 0 (Guzzle reads that as "no timeout") and not a rounded-up 1 (that hands out
        // time the budget does not have). Callers must read null as "do not start".
        $this->assertNull((new ExtractionBudget(0))->remainingSecondsForTimeout());
    }

    public function test_a_budget_with_under_a_second_left_has_no_safe_timeout(): void
    {
        $budget = new ExtractionBudget(2);
        usleep(1_200_000);

        // Still has ~0.8s, so it is not exhausted — but there is no whole second left to
        // hand out, and rounding up to 1 would overspend the deadline on every call.
        $this->assertFalse($budget->isExhausted());
        $this->assertGreaterThan(0.0, $budget->remainingSeconds());
        $this->assertNull($budget->remainingSecondsForTimeout());
    }

    public function test_the_timeout_value_is_rounded_down_so_the_budget_is_not_overspent(): void
    {
        $timeout = (new ExtractionBudget(10))->remainingSecondsForTimeout();

        $this->assertNotNull($timeout);
        $this->assertLessThanOrEqual(10, $timeout);
    }

    public function test_it_defaults_to_the_configured_budget(): void
    {
        config()->set('price_buddy.meta_extraction.budget_seconds', 37);

        $this->assertSame(37, ExtractionBudget::start()->totalSeconds);
    }

    public function test_a_healing_fetch_stops_once_the_budget_is_exhausted(): void
    {
        // The agent's own fetches run in this process between model calls, so they are not
        // covered by the model call's timeout. Without this guard a tool-calling loop could
        // keep spending long after the request's deadline had passed.
        $context = new HealingContext(
            'https://example.com/product',
            new Store(['settings' => []]),
            null,
            new ExtractionBudget(0),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Extraction budget exhausted');

        $context->fetch(false);
    }
}
