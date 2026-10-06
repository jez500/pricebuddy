<?php

namespace Tests\Feature\Http;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class GuestRedirectTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Changing the password invalidates the other sessions of the user. The next request
     * must go to the Filament login page. The app has no route named "login" (#236).
     */
    public function test_session_with_stale_password_hash_redirects_to_filament_login(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->withSession(['password_hash_web' => Hash::make('an-old-password')])
            ->get('/admin')
            ->assertRedirect(route('filament.admin.auth.login'));

        $this->assertGuest();
    }

    public function test_guest_on_admin_redirects_to_filament_login(): void
    {
        $this->get('/admin')->assertRedirect(route('filament.admin.auth.login'));
    }
}
