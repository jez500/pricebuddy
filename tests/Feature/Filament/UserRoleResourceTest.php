<?php

namespace Tests\Feature\Filament;

use App\Enums\Role;
use App\Filament\Resources\UserResource\Pages\CreateUser;
use App\Filament\Resources\UserResource\Pages\EditUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class UserRoleResourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_sees_role_field_on_create()
    {
        $this->actingAs(User::factory()->admin()->create());

        Livewire::test(CreateUser::class)
            ->assertFormFieldExists('role')
            ->assertFormFieldIsVisible('role');
    }

    public function test_admin_can_create_user_with_chosen_role()
    {
        $this->actingAs(User::factory()->admin()->create());

        Livewire::test(CreateUser::class)
            ->fillForm([
                'name' => 'New Admin',
                'email' => 'newadmin@example.com',
                'password' => 'password',
                'role' => Role::Admin->value,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame(Role::Admin, User::whereEmail('newadmin@example.com')->sole()->role);
    }

    public function test_non_admin_does_not_see_role_field_when_editing_self()
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        // The field is in the schema but hidden via ->visible(isAdmin),
        // so assert it is hidden rather than absent.
        Livewire::test(EditUser::class, ['record' => $user->getKey()])
            ->assertFormFieldIsHidden('role');
    }
}
