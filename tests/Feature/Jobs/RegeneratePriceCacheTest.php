<?php

namespace Tests\Feature\Jobs;

use App\Jobs\RegeneratePriceCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class RegeneratePriceCacheTest extends TestCase
{
    use RefreshDatabase;

    public function test_job_calls_artisan_command()
    {
        Artisan::shouldReceive('call')
            ->once()
            ->with('buddy:regenerate-price-cache')
            ->andReturn(0);

        $job = new RegeneratePriceCache;
        $job->handle();

        $this->assertTrue(true); // Job executed successfully
    }
}
