<?php

namespace Tests\Feature\Migrations;

use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BackfillStoreCreatorTest extends TestCase
{
    use RefreshDatabase;

    public function test_backfill_sets_stores_with_no_creator_to_the_oldest_admin(): void
    {
        $user = User::factory()->create();
        $admin = User::factory()->admin()->create();

        $kept = Store::factory()->create(['user_id' => $user->id]);
        $ownerless = Store::factory()->create();
        Store::query()->whereKey($ownerless->id)->update(['user_id' => null]);

        $migration = require database_path('migrations/2026_10_11_000000_backfill_stores_user_id.php');
        $migration->up();

        $this->assertSame($admin->id, $ownerless->fresh()->user_id);
        $this->assertSame($user->id, $kept->fresh()->user_id);
    }
}
