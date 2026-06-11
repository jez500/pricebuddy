<?php

namespace Tests\Feature\Models;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserRoleTest extends TestCase
{
    use RefreshDatabase;

    public function test_role_is_cast_to_enum()
    {
        $user = User::factory()->create(['role' => Role::User]);

        $this->assertInstanceOf(Role::class, $user->refresh()->role);
        $this->assertSame(Role::User, $user->role);
    }

    public function test_is_admin_returns_true_only_for_admins()
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $user = User::factory()->create(['role' => Role::User]);

        $this->assertTrue($admin->isAdmin());
        $this->assertFalse($user->isAdmin());
    }

    public function test_factory_defaults_to_user_role()
    {
        $this->assertSame(Role::User, User::factory()->create()->role);
    }

    public function test_factory_admin_state_sets_admin_role()
    {
        $this->assertSame(Role::Admin, User::factory()->admin()->create()->role);
    }
}
