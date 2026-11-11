<?php

namespace Tests\Feature\Services\Oidc;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OidcLoginPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_page_has_no_sso_button_when_oidc_is_disabled(): void
    {
        $this->assertFalse(config('oidc.enabled'));

        $this->get('/admin/login')
            ->assertOk()
            ->assertDontSee('login/oidc');
    }

    public function test_no_oidc_connection_is_registered_when_disabled(): void
    {
        $this->assertSame([], config('oidc.connections'));
    }
}
