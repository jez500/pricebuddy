<?php

namespace App\Services\Scrapers;

use App\Services\Helpers\HtmlEncodingHelper;
use Exception;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Jez500\WebScraperForLaravel\WebScraperHttp;

/**
 * The package scraper, with the response body converted to UTF-8 before it is
 * cached or parsed (#203). AppServiceProvider binds it in place of WebScraperHttp.
 *
 * Unlike the package scraper, an error response is reported as an error rather than
 * parsed, and is not cached. An error page can still carry a title, so parsing it
 * would read as a product page with no price.
 */
class Utf8WebScraperHttp extends WebScraperHttp
{
    /**
     * Error statuses whose page is still parsed: a removed product's page is how the
     * soft 404 check marks it discontinued.
     */
    protected const array PARSED_ERROR_STATUSES = [404, 410];

    public function get(): self
    {
        $request = function (): ?string {
            try {
                $response = $this->getRequest()->get($this->url);

                if ($this->isFailedResponse($response)) {
                    $this->errors[] = [
                        'message' => 'HTTP '.$response->status().' response',
                        'code' => $response->status(),
                    ];

                    return null;
                }

                return HtmlEncodingHelper::toUtf8($response->body(), $response->header('Content-Type'));
            } catch (Exception $e) {
                $this->errors[] = [
                    'message' => $e->getMessage(),
                    'code' => $e->getCode(),
                ];
                logger()->error($e->getMessage());
            }

            return null;
        };

        // A null body is not cached, so the next scrape fetches the page again.
        $body = $this->useCache === true
            ? Cache::remember(
                $this->getCacheKey($this->url),
                now()->addMinutes($this->cacheMinsTtl),
                fn () => $request()
            )
            : $request();

        $this->body = $body ?? '';

        return $this;
    }

    protected function isFailedResponse(Response $response): bool
    {
        return $response->failed() && ! in_array($response->status(), self::PARSED_ERROR_STATUSES, true);
    }
}
