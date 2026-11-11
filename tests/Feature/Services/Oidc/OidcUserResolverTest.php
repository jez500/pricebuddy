<?php

namespace Tests\Feature\Services\Oidc;

use App\Enums\Role;
use App\Models\User;
use App\Services\Oidc\OidcUserResolver;
use ChrisReedIO\Socialment\Exceptions\AbortedLoginException;
use ChrisReedIO\Socialment\Models\ConnectedAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Decides who a first-time OIDC login becomes. Returning logins are matched on
 * the provider subject by Socialment and never reach the resolver.
 */
class OidcUserResolverTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('oidc.auto_register', true);
        config()->set('oidc.link_by_email', true);
    }

    protected function account(?string $email = 'new@example.com', ?string $name = 'New Person'): ConnectedAccount
    {
        return new ConnectedAccount(['email' => $email, 'name' => $name]);
    }

    protected function resolve(ConnectedAccount $account): User
    {
        return resolve(OidcUserResolver::class)($account);
    }

    public function test_creates_a_standard_user_for_an_unknown_email(): void
    {
        $user = $this->resolve($this->account());

        $this->assertTrue($user->exists);
        $this->assertSame('new@example.com', $user->email);
        $this->assertSame('New Person', $user->name);
        $this->assertSame(Role::User, $user->role);
    }

    public function test_new_user_gets_an_unguessable_password(): void
    {
        $user = $this->resolve($this->account());

        $this->assertNotEmpty($user->password);
        $this->assertFalse(Hash::check('', $user->password));
        $this->assertFalse(Hash::check('password', $user->password));
    }

    public function test_new_user_name_falls_back_to_the_email_local_part(): void
    {
        $user = $this->resolve($this->account('someone@example.com', null));

        $this->assertSame('someone', $user->name);
    }

    public function test_email_is_normalised_to_lowercase(): void
    {
        $user = $this->resolve($this->account('  Mixed.Case@Example.COM '));

        $this->assertSame('mixed.case@example.com', $user->email);
    }

    public function test_links_to_an_existing_user_with_the_same_email_ignoring_case(): void
    {
        $existing = User::factory()->admin()->create(['email' => 'admin@example.com']);

        $user = $this->resolve($this->account('Admin@Example.com'));

        $this->assertTrue($user->is($existing));
        $this->assertSame(Role::Admin, $user->fresh()->role);
        $this->assertSame(1, User::count());
    }

    public function test_refuses_to_link_an_existing_user_when_linking_is_disabled(): void
    {
        config()->set('oidc.link_by_email', false);
        User::factory()->create(['email' => 'taken@example.com']);

        $this->expectException(AbortedLoginException::class);

        $this->resolve($this->account('taken@example.com'));
    }

    public function test_refuses_unknown_users_when_auto_register_is_disabled(): void
    {
        config()->set('oidc.auto_register', false);

        try {
            $this->resolve($this->account());
            $this->fail('Expected the login to be refused.');
        } catch (AbortedLoginException) {
            $this->assertSame(0, User::count());
        }
    }

    public function test_auto_register_off_still_allows_linking_an_existing_user(): void
    {
        config()->set('oidc.auto_register', false);
        $existing = User::factory()->create(['email' => 'known@example.com']);

        $this->assertTrue($this->resolve($this->account('known@example.com'))->is($existing));
    }

    public function test_refuses_an_account_without_an_email(): void
    {
        foreach ([null, '', '   '] as $email) {
            try {
                $this->resolve($this->account($email));
                $this->fail('Expected the login to be refused.');
            } catch (AbortedLoginException) {
                $this->assertSame(0, User::count());
            }
        }
    }
}
