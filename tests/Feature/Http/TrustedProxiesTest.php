<?php

namespace Tests\Feature\Http;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class TrustedProxiesTest extends TestCase
{
    /**
     * Register a lightweight probe route that reports how the framework
     * resolved the request scheme and what an absolute URL looks like.
     */
    private function defineSchemeProbeRoute(): void
    {
        Route::get('/__scheme-probe', fn () => response()->json([
            'secure' => request()->isSecure(),
            'url' => url('/admin/logout'),
        ]));
    }

    public function test_forwarded_proto_https_from_trusted_proxy_generates_secure_urls(): void
    {
        $this->defineSchemeProbeRoute();

        $response = $this->get('/__scheme-probe', ['X-Forwarded-Proto' => 'https']);

        $response->assertSuccessful();

        $this->assertTrue(
            $response->json('secure'),
            'A request forwarded as HTTPS by a trusted proxy must be treated as secure.'
        );
        $this->assertStringStartsWith(
            'https://',
            $response->json('url'),
            'Absolute URLs (e.g. the logout form action) must be generated over HTTPS behind the proxy.'
        );
    }

    public function test_plain_http_request_without_forwarded_proto_stays_insecure(): void
    {
        $this->defineSchemeProbeRoute();

        $response = $this->get('/__scheme-probe');

        $response->assertSuccessful();

        $this->assertFalse(
            $response->json('secure'),
            'A plain HTTP request must not be forced to HTTPS (no blanket forceScheme).'
        );
        $this->assertStringStartsWith('http://', $response->json('url'));
    }
}
