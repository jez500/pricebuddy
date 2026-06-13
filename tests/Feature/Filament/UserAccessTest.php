<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\UserResource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_access_list_create_and_edit_any_user()
    {
        $admin = User::factory()->admin()->create();
        $other = User::factory()->create();
        $this->actingAs($admin);

        $this->get(UserResource::getUrl('index'))->assertOk();
        $this->get(UserResource::getUrl('create'))->assertOk();
        $this->get(UserResource::getUrl('edit', ['record' => $other->getKey()]))->assertOk();
    }

    public function test_non_admin_cannot_access_list_or_create()
    {
        $this->actingAs(User::factory()->create());

        $this->get(UserResource::getUrl('index'))->assertForbidden();
        $this->get(UserResource::getUrl('create'))->assertForbidden();
    }

    public function test_non_admin_cannot_edit_another_user()
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $this->actingAs($user);

        $this->get(UserResource::getUrl('edit', ['record' => $other->getKey()]))
            ->assertForbidden();
    }

    public function test_non_admin_can_edit_self()
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $this->get(UserResource::getUrl('edit', ['record' => $user->getKey()]))
            ->assertOk();
    }

    public function test_users_nav_hidden_for_non_admin()
    {
        $this->actingAs(User::factory()->create());

        $this->assertFalse(UserResource::canViewAny());
        $this->assertFalse(UserResource::shouldRegisterNavigation());
    }

    public function test_users_nav_visible_for_admin()
    {
        $this->actingAs(User::factory()->admin()->create());

        $this->assertTrue(UserResource::canViewAny());
        $this->assertTrue(UserResource::shouldRegisterNavigation());
    }
}
