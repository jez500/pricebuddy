<?php

namespace Tests\Unit\Rules;

use App\Rules\PublicHttpUrl;
use Tests\TestCase;

class PublicHttpUrlTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    protected function runRule(string $value, ?PublicHttpUrl $rule = null): array
    {
        $failures = [];

        ($rule ?? new PublicHttpUrl)->validate('url', $value, function (string $message) use (&$failures): void {
            $failures[] = $message;
        });

        return $failures;
    }

    /**
     * @dataProvider publicUrls
     */
    public function test_it_allows_public_http_urls(string $url): void
    {
        $this->assertSame([], $this->runRule($url), "Expected {$url} to be allowed");
    }

    /**
     * @return array<string, array<int, string>>
     */
    public static function publicUrls(): array
    {
        return [
            'public ipv4 literal' => ['http://93.184.216.34/'],
            'public ipv4 literal https' => ['https://1.1.1.1/'],
            'public ipv6 literal' => ['http://[2606:2800:220:1:248:1893:25c8:1946]/'],
        ];
    }

    /**
     * @dataProvider blockedUrls
     */
    public function test_it_blocks_internal_reserved_and_non_http_urls(string $url): void
    {
        $this->assertNotEmpty($this->runRule($url), "Expected {$url} to be blocked");
    }

    /**
     * @return array<string, array<int, string>>
     */
    public static function blockedUrls(): array
    {
        return [
            'ftp scheme' => ['ftp://example.com/'],
            'file scheme' => ['file:///etc/passwd'],
            'gopher scheme' => ['gopher://127.0.0.1/'],
            'no scheme' => ['example.com/path'],
            'localhost name' => ['http://localhost/'],
            'localhost subdomain' => ['http://api.localhost/'],
            'loopback ipv4' => ['http://127.0.0.1/'],
            'loopback ipv4 range' => ['http://127.1.2.3/'],
            'loopback ipv6' => ['http://[::1]/'],
            'unspecified ipv4' => ['http://0.0.0.0/'],
            'cloud metadata' => ['http://169.254.169.254/latest/meta-data/'],
            'link local ipv6' => ['http://[fe80::1]/'],
            'private 10' => ['http://10.0.0.5/'],
            'private 172' => ['http://172.16.0.1/'],
            'private 192' => ['http://192.168.1.1/'],
            'cgnat 100.64' => ['http://100.64.0.1/'],
            'ula ipv6' => ['http://[fc00::1]/'],
            'ipv4 mapped loopback' => ['http://[::ffff:127.0.0.1]/'],
            'broadcast' => ['http://255.255.255.255/'],
        ];
    }

    public function test_it_blocks_public_hostnames_that_resolve_to_private_ips(): void
    {
        $rule = new class extends PublicHttpUrl
        {
            protected function resolveHostIps(string $host): array
            {
                return ['10.0.0.20'];
            }
        };

        $this->assertNotEmpty($this->runRule('http://internal.example.com/', $rule));
    }

    public function test_it_blocks_hostnames_that_cannot_be_resolved(): void
    {
        $rule = new class extends PublicHttpUrl
        {
            protected function resolveHostIps(string $host): array
            {
                return [];
            }
        };

        $this->assertNotEmpty($this->runRule('http://does-not-resolve.example/', $rule));
    }

    public function test_it_allows_a_hostname_that_resolves_to_a_public_ip(): void
    {
        $rule = new class extends PublicHttpUrl
        {
            protected function resolveHostIps(string $host): array
            {
                return ['93.184.216.34'];
            }
        };

        $this->assertSame([], $this->runRule('http://example.com/', $rule));
    }
}
