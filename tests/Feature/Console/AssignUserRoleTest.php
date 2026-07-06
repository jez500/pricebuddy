<?php

namespace Tests\Feature\Console;

use App\Console\Commands\AssignUserRole;
use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AssignUserRoleTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_assigns_the_given_role_to_the_user_with_that_email()
    {
        $user = User::factory()->create([
            'email' => 'someone@example.com',
            'role' => Role::User,
        ]);

        $this->artisan(AssignUserRole::COMMAND, [
            'email' => 'someone@example.com',
            'role' => Role::Admin->value,
        ])->assertExitCode(0);

        $this->assertSame(Role::Admin, $user->refresh()->role);
    }

    public function test_it_can_demote_a_user_to_the_user_role()
    {
        $user = User::factory()->create([
            'email' => 'admin@example.com',
            'role' => Role::Admin,
        ]);

        $this->artisan(AssignUserRole::COMMAND, [
            'email' => 'admin@example.com',
            'role' => Role::User->value,
        ])->assertExitCode(0);

        $this->assertSame(Role::User, $user->refresh()->role);
    }

    public function test_it_fails_when_the_email_does_not_match_a_user()
    {
        $this->artisan(AssignUserRole::COMMAND, [
            'email' => 'missing@example.com',
            'role' => Role::Admin->value,
        ])->assertExitCode(1);
    }

    public function test_it_fails_when_the_role_is_invalid()
    {
        $user = User::factory()->create([
            'email' => 'someone@example.com',
            'role' => Role::User,
        ]);

        $this->artisan(AssignUserRole::COMMAND, [
            'email' => 'someone@example.com',
            'role' => 'superadmin',
        ])->assertExitCode(1);

        $this->assertSame(Role::User, $user->refresh()->role);
    }
}
