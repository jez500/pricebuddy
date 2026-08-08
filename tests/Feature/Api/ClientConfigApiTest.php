<?php

namespace Tests\Feature\Api;

use App\Enums\ApiAbility;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class ClientConfigApiTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    private function authenticateWith(array $abilities): void
    {
        Auth::forgetGuards();
        $token = $this->user->createToken('test-token', $abilities)->plainTextToken;
        $this->withHeaders(['Authorization' => 'Bearer '.$token]);
    }

    public function test_readable_with_a_minimal_ability_token(): void
    {
        $this->authenticateWith([ApiAbility::ClientConfigRead->value]);

        $this->getJson('/api/client-config')->assertSuccessful();
    }

    public function test_readable_with_an_all_access_token(): void
    {
        $this->authenticateWith(['*']);

        $this->getJson('/api/client-config')->assertSuccessful();
    }

    public function test_forbidden_without_the_ability(): void
    {
        $this->authenticateWith([ApiAbility::UserDetail->value]);

        $this->getJson('/api/client-config')->assertForbidden();
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->getJson('/api/client-config')->assertUnauthorized();
    }

    public function test_response_keys_match_an_exact_expected_set(): void
    {
        $this->authenticateWith(['*']);

        $payload = $this->getJson('/api/client-config')->assertSuccessful()->json();

        $this->assertSame(['data'], array_keys($payload));
        $this->assertSame(['capabilities', 'limits', 'app_version'], array_keys($payload['data']));
        $this->assertSame(
            ['products_filter_url', 'products_current_url', 'products_sparse_fieldsets', 'stores_filter_domain'],
            array_keys($payload['data']['capabilities'])
        );
        $this->assertSame(['meta_extraction_timeout_seconds'], array_keys($payload['data']['limits']));
    }

    public function test_meta_extraction_limit_comes_from_config(): void
    {
        config()->set('price_buddy.meta_extraction.budget_seconds', 40);
        $this->authenticateWith(['*']);

        $this->getJson('/api/client-config')
            ->assertSuccessful()
            ->assertJsonPath('data.limits.meta_extraction_timeout_seconds', 40);
    }

    public function test_app_version_comes_from_config(): void
    {
        config()->set('app.version', '9.9.9');
        $this->authenticateWith(['*']);

        $this->getJson('/api/client-config')
            ->assertSuccessful()
            ->assertJsonPath('data.app_version', '9.9.9');
    }

    public function test_etag_is_stable_and_if_none_match_returns_304(): void
    {
        $this->authenticateWith(['*']);

        $first = $this->getJson('/api/client-config')->assertSuccessful();
        $etag = $first->headers->get('ETag');

        $this->assertNotEmpty($etag);
        $this->assertSame($etag, $this->getJson('/api/client-config')->headers->get('ETag'));

        $this->withHeaders(['If-None-Match' => $etag])
            ->getJson('/api/client-config')
            ->assertStatus(304);
    }

    public function test_cache_control_is_private_for_a_day(): void
    {
        $this->authenticateWith(['*']);

        $cacheControl = $this->getJson('/api/client-config')->headers->get('Cache-Control');

        $this->assertStringContainsString('private', (string) $cacheControl);
        $this->assertStringContainsString('max-age=86400', (string) $cacheControl);
    }
}
