<?php

namespace App\Services\Scrapers;

use App\Services\Helpers\HtmlEncodingHelper;
use Exception;
use Illuminate\Support\Facades\Cache;
use Jez500\WebScraperForLaravel\WebScraperHttp;

/**
 * The package scraper, with the response body converted to UTF-8 before it is
 * cached or parsed (#203). AppServiceProvider binds it in place of WebScraperHttp.
 */
class Utf8WebScraperHttp extends WebScraperHttp
{
    public function get(): self
    {
        $request = function () {
            try {
                $response = $this->getRequest()->get($this->url);

                return HtmlEncodingHelper::toUtf8($response->body(), $response->header('Content-Type'));
            } catch (Exception $e) {
                $this->errors[] = [
                    'message' => $e->getMessage(),
                    'code' => $e->getCode(),
                ];
                logger()->error($e->getMessage());
            }

            return '';
        };

        $this->body = $this->useCache === true
            ? Cache::remember(
                $this->getCacheKey($this->url),
                now()->addMinutes($this->cacheMinsTtl),
                fn () => $request()
            )
            : $request();

        return $this;
    }
}
