<?php

namespace Tests\Unit\Models;

use App\Models\Url;
use Tests\TestCase;

class UrlNormalizeForMatchTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function normalisationProvider(): array
    {
        return [
            'strips www and lowercases host' => ['https://WWW.Target.com.au/p/Xbox/?ref=nav', 'target.com.au/p/xbox'],
            'scheme is irrelevant' => ['http://target.com.au/p/xbox', 'target.com.au/p/xbox'],
            'trailing slash removed' => ['https://target.com.au/p/xbox/', 'target.com.au/p/xbox'],
            'fragment discarded' => ['https://shop.com/p/x#reviews', 'shop.com/p/x'],
            'port discarded' => ['https://shop.com:8443/p/x', 'shop.com/p/x'],
            'no path' => ['https://shop.com', 'shop.com'],
            'bare slash path' => ['https://shop.com/', 'shop.com'],
            'scheme-less input assumes https' => ['target.com.au/p/xbox', 'target.com.au/p/xbox'],
            'tracking dropped, significant kept' => ['https://shop.com/p/tee?variant=42&utm_source=fb&gclid=x', 'shop.com/p/tee?variant=42'],
            'param order does not matter' => ['https://shop.com/p/tee?utm_medium=x&variant=42', 'shop.com/p/tee?variant=42'],
            'remaining params sorted' => ['https://shop.com/p/tee?sku=B&pid=A', 'shop.com/p/tee?pid=a&sku=b'],
            'affinity survives aff denylist' => ['https://shop.com/p/tee?affinity=hi&aff=x&aff_id=y', 'shop.com/p/tee?affinity=hi'],
            'amazon affiliate params' => ['https://amazon.com.au/dp/B01?th=1&psc=1&tag=aff-22', 'amazon.com.au/dp/b01'],
            'amazon search params' => ['https://amazon.com.au/dp/B01?keywords=x&qid=1&sr=8-1&pd_rd_w=z&ref_=sr', 'amazon.com.au/dp/b01'],
            'valueless param kept verbatim' => ['https://shop.com/p/x?foo', 'shop.com/p/x?foo'],
            'empty valued param kept verbatim' => ['https://shop.com/p/x?foo=', 'shop.com/p/x?foo='],
            'malformed input' => ['not a url', ''],
            'empty input' => ['', ''],
            'whitespace only' => ['   ', ''],
            'no host' => ['https://', ''],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('normalisationProvider')]
    public function test_normalize_for_match(string $input, string $expected): void
    {
        $this->assertSame($expected, Url::normalizeForMatch($input));
    }

    public function test_result_is_truncated_to_255_characters(): void
    {
        $long = 'https://shop.com/'.str_repeat('a', 400);

        $this->assertSame(255, strlen(Url::normalizeForMatch($long)));
    }

    public function test_normalize_host(): void
    {
        $this->assertSame('target.com.au', Url::normalizeHost('www.Target.com.au'));
        $this->assertSame('target.com.au', Url::normalizeHost('TARGET.COM.AU'));
        $this->assertSame('target.com.au', Url::normalizeHost('target.com.au:8443'));
        $this->assertSame('', Url::normalizeHost('  '));
    }

    public function test_denylist_is_config_driven(): void
    {
        config()->set('url_matching.tracking_params', ['mycustomparam']);
        config()->set('url_matching.tracking_param_prefixes', []);

        $this->assertSame(
            'shop.com/p/x?gclid=keep',
            Url::normalizeForMatch('https://shop.com/p/x?mycustomparam=drop&gclid=keep')
        );
    }

    public function test_config_prefixes_are_applied(): void
    {
        config()->set('url_matching.tracking_params', []);
        config()->set('url_matching.tracking_param_prefixes', ['xyz_']);

        $this->assertSame(
            'shop.com/p/x?keep=1',
            Url::normalizeForMatch('https://shop.com/p/x?xyz_a=1&keep=1')
        );
    }

    public function test_config_values_are_trimmed_and_lowercased(): void
    {
        config()->set('url_matching.tracking_params', [' GCLID ', '', 'gclid']);
        config()->set('url_matching.tracking_param_prefixes', []);

        $this->assertSame('shop.com/p/x', Url::normalizeForMatch('https://shop.com/p/x?gclid=abc'));
    }

    public function test_env_extra_appends_rather_than_replaces(): void
    {
        $defaults = require config_path('url_matching.php');

        $this->assertContains('gclid', $defaults['tracking_params']);
        $this->assertContains('utm_', $defaults['tracking_param_prefixes']);
        $this->assertGreaterThanOrEqual(27, count(array_filter($defaults['tracking_params'])));
    }
}
