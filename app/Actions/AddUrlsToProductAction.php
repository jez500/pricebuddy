<?php

namespace App\Actions;

use App\Jobs\AddUrlToProductJob;
use App\Models\Product;
use App\Models\Url;

/**
 * Add several URLs to a product at once (#206). Each URL is scraped by a queued job,
 * because one scrape can take up to 30 seconds.
 */
class AddUrlsToProductAction
{
    public const int MAX_URLS = 50;

    public const string SKIP_INVALID = 'invalid';

    public const string SKIP_DUPLICATE = 'duplicate';

    public const string SKIP_EXISTING = 'existing';

    /**
     * @param  string|array<int, string>  $urls  a list of URLs, or text with one URL per line
     * @return array{queued: array<int, string>, skipped: array<int, array{url: string, reason: string}>}
     */
    public function __invoke(Product $product, string|array $urls, int $userId, float $priceFactor = 1): array
    {
        $existing = $product->urls()->pluck('url_normalized')->filter()->flip();
        $seen = [];
        $queued = [];
        $skipped = [];

        foreach (self::parse($urls) as $url) {
            $normalized = Url::normalizeForMatch($url);

            if (! self::isValidUrl($url) || $normalized === '') {
                $skipped[] = ['url' => $url, 'reason' => self::SKIP_INVALID];
            } elseif (isset($seen[$normalized])) {
                $skipped[] = ['url' => $url, 'reason' => self::SKIP_DUPLICATE];
            } elseif ($existing->has($normalized)) {
                $skipped[] = ['url' => $url, 'reason' => self::SKIP_EXISTING];
            } else {
                $seen[$normalized] = true;
                $queued[] = $url;
            }
        }

        foreach ($queued as $url) {
            AddUrlToProductJob::dispatch($product->getKey(), $url, $userId, $priceFactor);
        }

        return ['queued' => $queued, 'skipped' => $skipped];
    }

    /**
     * Split text on whitespace. Commas are not separators, because a URL can contain them.
     *
     * @param  string|array<int, string>  $urls
     * @return array<int, string>
     */
    public static function parse(string|array $urls): array
    {
        $urls = is_string($urls) ? preg_split('/\s+/', $urls) : $urls;

        return collect($urls)
            ->map(fn ($url) => trim((string) $url))
            ->filter()
            ->values()
            ->all();
    }

    public static function isValidUrl(string $url): bool
    {
        return filter_var($url, FILTER_VALIDATE_URL) !== false
            && in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true);
    }
}
