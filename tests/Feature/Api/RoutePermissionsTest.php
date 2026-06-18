<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class RoutePermissionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_endpoint_requires_its_ability(): void
    {
        $user = User::factory()->create();

        $scoped = $user->createToken('scoped', ['product:detail'])->plainTextToken;
        $this->withHeaders(['Authorization' => 'Bearer '.$scoped])
            ->getJson('/api/user')
            ->assertForbidden();

        // Reset the auth guard so the second request authenticates fresh with the new token.
        Auth::forgetGuards();

        $allowed = $user->createToken('allowed', ['user:detail'])->plainTextToken;
        $this->withHeaders(['Authorization' => 'Bearer '.$allowed])
            ->getJson('/api/user')
            ->assertOk();
    }

    public function test_meta_extraction_endpoint_requires_its_ability(): void
    {
        $user = User::factory()->create();

        $scoped = $user->createToken('scoped', ['product:detail'])->plainTextToken;
        $this->withHeaders(['Authorization' => 'Bearer '.$scoped])
            ->postJson('/api/meta-extraction', ['url' => 'https://example.com/p'])
            ->assertForbidden();
    }

    public function test_every_sanctum_api_route_also_enforces_an_ability(): void
    {
        // The plugin's logout route is auth:sanctum-only by design — no ability needed.
        // We exempt by URI because the plugin generates a non-deterministic route name.
        $exemptUris = ['api/auth/logout'];

        $offenders = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route) => str_starts_with($route->uri(), 'api/'))
            ->filter(fn ($route) => in_array('auth:sanctum', $route->gatherMiddleware(), true))
            ->reject(fn ($route) => in_array($route->uri(), $exemptUris, true))
            ->reject(fn ($route) => collect($route->gatherMiddleware())
                ->contains(fn ($m) => is_string($m) && str_starts_with($m, 'ability:')))
            ->map(fn ($route) => $route->getName() ?? $route->uri())
            ->values()
            ->all();

        $this->assertSame([], $offenders, 'API routes missing an ability: middleware: '.implode(', ', $offenders));
    }
}
