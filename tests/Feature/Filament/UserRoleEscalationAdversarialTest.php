<?php

namespace Tests\Feature\Filament;

use App\Enums\Role;
use App\Filament\Resources\UserResource\Pages\EditUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class UserRoleEscalationAdversarialTest extends TestCase
{
    use RefreshDatabase;

    public function test_non_admin_cannot_persist_forged_role_via_fill_form(): void
    {
        $user = User::factory()->create(['role' => Role::User]);
        $this->actingAs($user);

        Livewire::test(EditUser::class, ['record' => $user->getKey()])
            ->fillForm([
                'name' => 'Still Normal',
                'role' => Role::Admin->value,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(
            Role::User,
            $user->refresh()->role,
            'Non-admin must NOT be able to escalate their own role.'
        );
    }

    public function test_non_admin_cannot_persist_forged_role_via_raw_data_set(): void
    {
        $user = User::factory()->create(['role' => Role::User]);
        $this->actingAs($user);

        // Directly inject role into the Livewire data bag, bypassing the form UI.
        Livewire::test(EditUser::class, ['record' => $user->getKey()])
            ->set('data.role', Role::Admin->value)
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(
            Role::User,
            $user->refresh()->role,
            'Forged data.role injection must not escalate a non-admin.'
        );
    }

    public function test_non_admin_cannot_see_delete_action_on_own_edit_page(): void
    {
        $user = User::factory()->create(['role' => Role::User]);
        $this->actingAs($user);

        Livewire::test(EditUser::class, ['record' => $user->getKey()])
            ->assertActionHidden('delete');
    }

    public function test_admin_sees_delete_action(): void
    {
        $admin = User::factory()->admin()->create();
        $other = User::factory()->create();
        $this->actingAs($admin);

        Livewire::test(EditUser::class, ['record' => $other->getKey()])
            ->assertActionVisible('delete');
    }

    public function test_guest_is_redirected_from_user_resource(): void
    {
        $user = User::factory()->create();

        $this->get(\App\Filament\Resources\UserResource::getUrl('edit', ['record' => $user->getKey()]))
            ->assertRedirect();
    }
}
