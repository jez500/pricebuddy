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

    public function test_the_timeout_value_never_reaches_zero(): void
    {
        // Guzzle reads a timeout of 0 as "no timeout", which is the exact failure this
        // class exists to prevent, so an exhausted budget still hands out 1 second.
        $this->assertSame(1, (new ExtractionBudget(0))->remainingSecondsForTimeout());
    }

    public function test_the_timeout_value_is_rounded_down_so_the_budget_is_not_overspent(): void
    {
        $this->assertLessThanOrEqual(10, (new ExtractionBudget(10))->remainingSecondsForTimeout());
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
