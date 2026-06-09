<?php

namespace App\Services\Ai;

use App\Enums\ScraperService;
use App\Models\Store;
use App\Services\StrategyExtractor;
use Illuminate\Support\Str;
use Jez500\WebScraperForLaravel\Facades\WebScraper;
use Jez500\WebScraperForLaravel\WebScraperApi;
use Jez500\WebScraperForLaravel\WebScraperInterface;
use Throwable;

class HealingContext
{
    /**
     * Max characters of HTML returned to the agent per fetch (token guard).
     * The full raw HTML is retained internally for selector validation.
     */
    protected const int RETURN_BUDGET = 40000;

    protected ?string $html;

    protected bool $usedBrowser = false;

    public function __construct(
        public readonly string $url,
        public readonly Store $store,
        ?string $initialHtml = null,
    ) {
        $this->html = $initialHtml;
    }

    public function getHtml(): ?string
    {
        return $this->html;
    }

    /**
     * Whether browser (JS-rendered) scraping was used to obtain a usable page.
     * Signals that the resulting store should scrape via the browser service,
     * because the static/HTTP fetch was insufficient (e.g. bot-blocked).
     */
    public function usedBrowser(): bool
    {
        return $this->usedBrowser;
    }

    /**
     * Fetch the page HTML (static or browser-rendered), retain the raw body for
     * validation, and return a model-friendly view for the agent.
     */
    public function fetch(bool $rendered): string
    {
        $service = $rendered ? ScraperService::Api->value : ScraperService::Http->value;

        $scraper = WebScraper::make($service)->setUrl($this->url);

        if ($scraper instanceof WebScraperApi) {
            $scraper->setScraperApiBaseUrl(config('price_buddy.scraper_api_url', 'http://scraper:3000'));
        }

        if (filled($this->store->cookies)) {
            $scraper->setCookies($this->store->cookies);
        }

        $this->html = $scraper->setOptions($this->store->scraper_options)->get()->getBody();

        if ($rendered) {
            $this->usedBrowser = true;
        }

        return $this->htmlForModel((string) $this->html);
    }

    /**
     * Build the HTML view shown to the agent. Leads with the page's structured
     * product signals (JSON-LD, title, meta tags, first h1) so they remain visible
     * even on very large pages where the head/scripts would push them past the
     * return budget, then appends a truncated copy of the raw HTML. Validation
     * always runs against the full raw HTML, so selectors are still checked for real.
     */
    protected function htmlForModel(string $raw): string
    {
        $parts = [];

        if (preg_match_all('#<script\b[^>]*type=["\']?application/ld\+json["\']?[^>]*>.*?</script>#is', $raw, $ld)) {
            $parts = array_merge($parts, $ld[0]);
        }

        if (preg_match('#<title\b[^>]*>.*?</title>#is', $raw, $title)) {
            $parts[] = $title[0];
        }

        if (preg_match_all('#<meta\b[^>]*>#i', $raw, $meta)) {
            $parts = array_merge($parts, $meta[0]);
        }

        if (preg_match('#<h1\b[^>]*>.*?</h1>#is', $raw, $h1)) {
            $parts[] = $h1[0];
        }

        $signal = Str::limit(trim(implode("\n", $parts)), (int) (self::RETURN_BUDGET * 0.6), '');
        $body = Str::limit($raw, max(0, self::RETURN_BUDGET - strlen($signal)), '');

        if ($signal === '') {
            return $body;
        }

        return "<!-- extracted product signals (JSON-LD, title, meta, h1) -->\n".$signal
            ."\n\n<!-- page HTML (truncated) -->\n".$body;
    }

    /**
     * Validate a selector/regex against the loaded HTML.
     *
     * @return array{matched: bool, value: ?string, error: ?string}
     */
    public function validate(string $type, string $value): array
    {
        if (blank($this->html)) {
            return ['matched' => false, 'value' => null, 'error' => 'No HTML loaded yet; call fetch first.'];
        }

        try {
            $extracted = StrategyExtractor::extract($this->scraper(), ['type' => $type, 'value' => $value], 'price');
        } catch (Throwable $e) {
            return ['matched' => false, 'value' => null, 'error' => $e->getMessage()];
        }

        return filled($extracted)
            ? ['matched' => true, 'value' => $extracted, 'error' => null]
            : ['matched' => false, 'value' => null, 'error' => 'Selector matched nothing.'];
    }

    protected function scraper(): WebScraperInterface
    {
        return WebScraper::make(ScraperService::Http->value)->setBody((string) $this->html);
    }
}
