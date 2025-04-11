<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\Login;
use App\Filament\Resources\UserResource;
use App\Filament\Resources\UserResource\Pages\EditUser;
use App\Models\User;
use App\Services\Helpers\NotificationsHelper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

class UserTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        User::query()->delete();

        $this->user = User::factory()->create([
            'name' => 'Tester',
            'email' => 'tester@test.com',
            'password' => Hash::make('password'),
        ]);
    }

    public function test_user_login()
    {
        $this->get(route('filament.admin.auth.login'))
            ->assertOk();

        Livewire::test(Login::class)
            ->fillForm([
                'email' => $this->user->email,
                'password' => 'password',
            ])
            ->call('authenticate')
            ->assertHasNoFormErrors()
            ->assertRedirect(route('filament.admin.pages.home-dashboard'));
    }

    public function test_user_index()
    {
        $this->actingAs($this->user);

        $this->get(UserResource::getUrl('index'))->assertOk();
    }

    public function test_edit_user()
    {
        $this->actingAs($this->user);
        $params = ['record' => $this->user->getKey()];

        $this->get(UserResource::getUrl('edit', $params))->assertOk();

        Livewire::test(EditUser::class, $params)
            ->fillForm([
                'name' => 'Updated Name',
                'email' => $this->user->email,
                'password' => 'password',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas(User::class, [
            'id' => $this->user->getKey(),
            'name' => 'Updated Name',
        ]);
    }

    public function test_edit_user_settings()
    {
        $this->actingAs($this->user);
        $params = ['record' => $this->user->getKey()];

        $this->assertFalse(NotificationsHelper::getUserEnabled($this->user, 'mail'));
        $this->assertFalse(NotificationsHelper::getUserEnabled($this->user, 'pushover'));
        $this->assertFalse(NotificationsHelper::getUserEnabled($this->user, 'gotify'));

        Livewire::test(EditUser::class, $params)
            ->fillForm(array_merge($this->user->toArray(), [
                'settings.notifications.mail.enabled' => true,
                'settings.notifications.pushover.enabled' => true,
                'settings.notifications.pushover.user_key' => 'po_token',
                'settings.notifications.gotify.enabled' => true,
                'settings.notifications.gotify.token' => 'po_token',
                'settings.notifications.gotify.url' => 'http://gotify.example.com',
            ]))
            ->call('save')
            ->assertHasNoFormErrors();

        $this->user->refresh();

        $this->assertTrue(NotificationsHelper::getUserEnabled($this->user, 'mail'));
        $this->assertTrue(NotificationsHelper::getUserEnabled($this->user, 'pushover'));
        $this->assertTrue(NotificationsHelper::getUserEnabled($this->user, 'gotify'));

        $this->assertSame(
            'po_token',
            NotificationsHelper::getUserServices($this->user)->get('pushover')['user_key']
        );
        $this->assertSame(
            'po_token',
            NotificationsHelper::getUserServices($this->user)->get('gotify')['token']
        );

    }

    public function test_user_create()
    {
        $this->actingAs($this->user);

        $this->get(UserResource::getUrl('create'))->assertOk();

        Livewire::test(UserResource\Pages\CreateUser::class)
            ->fillForm([
                'name' => 'New user',
                'email' => 'newuser@example.com',
                'password' => 'password',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas(User::class, [
            'email' => 'newuser@example.com',
            'name' => 'New user',
        ]);
    }
}
