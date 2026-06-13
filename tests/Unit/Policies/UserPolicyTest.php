<?php

namespace Tests\Unit\Policies;

use App\Models\User;
use App\Policies\UserPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserPolicyTest extends TestCase
{
    use RefreshDatabase;

    private UserPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->policy = new UserPolicy;
    }

    public function test_admin_can_do_everything()
    {
        $admin = User::factory()->admin()->create();
        $other = User::factory()->create();

        $this->assertTrue($this->policy->viewAny($admin));
        $this->assertTrue($this->policy->create($admin));
        $this->assertTrue($this->policy->view($admin, $other));
        $this->assertTrue($this->policy->update($admin, $other));
        $this->assertTrue($this->policy->delete($admin, $other));
    }

    public function test_non_admin_cannot_manage_others()
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        $this->assertFalse($this->policy->viewAny($user));
        $this->assertFalse($this->policy->create($user));
        $this->assertFalse($this->policy->view($user, $other));
        $this->assertFalse($this->policy->update($user, $other));
        $this->assertFalse($this->policy->delete($user, $other));
    }

    public function test_non_admin_can_view_and_update_self_but_not_delete()
    {
        $user = User::factory()->create();

        $this->assertTrue($this->policy->view($user, $user));
        $this->assertTrue($this->policy->update($user, $user));
        $this->assertFalse($this->policy->delete($user, $user));
    }

    public function test_admin_cannot_delete_self()
    {
        $admin = User::factory()->admin()->create();
        $other = User::factory()->admin()->create();

        $this->assertFalse($this->policy->delete($admin, $admin));
        $this->assertTrue($this->policy->delete($admin, $other));
    }

    public function test_admin_cannot_delete_the_last_admin()
    {
        $admin = User::factory()->admin()->create();
        $soleAdmin = User::factory()->admin()->create();

        $this->assertTrue($this->policy->delete($admin, $soleAdmin));

        $admin->delete();

        $this->assertFalse($this->policy->delete($soleAdmin, $soleAdmin));
    }

    public function test_policy_is_registered_and_enforced_through_the_gate()
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();

        $this->assertTrue($admin->can('viewAny', User::class));
        $this->assertFalse($user->can('viewAny', User::class));

        $this->assertTrue($user->can('update', $user));
        $this->assertFalse($user->can('update', $admin));
        $this->assertFalse($user->can('delete', $user));
    }
}
