<?php

namespace Tests\Feature\Models;

use App\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class StoreHealingSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_self_healing_disabled_defaults_to_false(): void
    {
        $store = Store::factory()->create(['settings' => []]);

        $this->assertFalse($store->ai_self_healing_disabled);
    }

    public function test_self_healing_disabled_reads_from_settings(): void
    {
        $store = Store::factory()->create(['settings' => ['ai_self_healing_disabled' => true]]);

        $this->assertTrue($store->ai_self_healing_disabled);
    }

    public function test_marks_and_clears_heal_failure_timestamp(): void
    {
        Carbon::setTestNow('2026-06-07 10:00:00');
        $store = Store::factory()->create(['settings' => []]);

        $this->assertNull($store->getAiHealFailedAt());

        $store->markAiHealFailed();
        $this->assertTrue($store->fresh()->getAiHealFailedAt()->equalTo(Carbon::parse('2026-06-07 10:00:00')));

        $store->clearAiHealFailed();
        $this->assertNull($store->fresh()->getAiHealFailedAt());

        Carbon::setTestNow();
    }
}
