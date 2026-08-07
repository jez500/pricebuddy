<?php

namespace Tests\Feature\Console;

use App\Models\Url;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RenormalizeUrlsTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_rebuilds_stale_normalized_values(): void
    {
        $url = Url::factory()->create(['url' => 'https://shop.com/p/x?variant=1']);
        DB::table('urls')->where('id', $url->id)->update(['url_normalized' => 'stale.value']);

        $this->artisan('urls:renormalize')
            ->assertSuccessful();

        $this->assertSame('shop.com/p/x?variant=1', $url->fresh()->url_normalized);
    }

    public function test_it_picks_up_a_denylist_change(): void
    {
        $url = Url::factory()->create(['url' => 'https://shop.com/p/x?variant=1']);

        config()->set('url_matching.tracking_params', ['variant']);

        $this->artisan('urls:renormalize')->assertSuccessful();

        $this->assertSame('shop.com/p/x', $url->fresh()->url_normalized);
    }

    public function test_it_reports_a_zero_change_run(): void
    {
        Url::factory()->create(['url' => 'https://shop.com/p/x']);

        $this->artisan('urls:renormalize')
            ->expectsOutputToContain('0')
            ->assertSuccessful();
    }
}
