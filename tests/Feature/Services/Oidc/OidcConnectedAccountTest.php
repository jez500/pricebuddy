<?php

namespace Tests\Feature\Services\Oidc;

use App\Models\User;
use ChrisReedIO\Socialment\Models\ConnectedAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Socialment saves the provider's tokens in plain text. PriceBuddy never uses
 * them after login, so they must not reach the database.
 */
class OidcConnectedAccountTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Saved the way Socialment does it: the user is associated, not mass assigned.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function account(array $attributes): ConnectedAccount
    {
        $account = new ConnectedAccount(['provider' => 'oidc', 'provider_user_id' => 'sub-123', ...$attributes]);
        $account->user()->associate(User::factory()->create())->save();

        return $account;
    }

    public function test_provider_tokens_are_not_stored_on_create(): void
    {
        $account = $this->account([
            'email' => 'person@example.com',
            'token' => 'secret-access-token',
            'refresh_token' => 'secret-refresh-token',
        ]);

        $stored = $account->fresh();

        $this->assertSame('', $stored->token);
        $this->assertNull($stored->refresh_token);
        $this->assertSame('person@example.com', $stored->email);
    }

    public function test_provider_tokens_are_not_stored_on_later_logins(): void
    {
        $account = $this->account(['token' => '']);

        $account->update(['token' => 'new-access-token', 'refresh_token' => 'new-refresh-token']);

        $stored = $account->fresh();

        $this->assertSame('', $stored->token);
        $this->assertNull($stored->refresh_token);
    }
}
