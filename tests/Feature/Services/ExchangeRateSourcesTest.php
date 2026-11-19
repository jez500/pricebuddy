<?php

namespace Tests\Feature\Services;

use App\Exceptions\ExchangeRateException;
use App\Services\ExchangeRates\FrankfurterRateSource;
use App\Services\ExchangeRates\YahooFinanceRateSource;
use Carbon\Carbon;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ExchangeRateSourcesTest extends TestCase
{
    public function test_frankfurter_returns_rates_keyed_by_date(): void
    {
        Http::fake([
            FrankfurterRateSource::BASE_URL.'/*' => Http::response([
                'amount' => 1.0,
                'base' => 'USD',
                'start_date' => '2026-11-10',
                'end_date' => '2026-11-13',
                'rates' => [
                    '2026-11-11' => ['CAD' => 1.4235],
                    '2026-11-10' => ['CAD' => 1.4136],
                    '2026-11-12' => ['CAD' => null],
                ],
            ]),
        ]);

        $rates = (new FrankfurterRateSource)->fetch('USD', 'CAD', Carbon::parse('2026-11-10'), Carbon::parse('2026-11-14'));

        $this->assertSame(['2026-11-10' => 1.4136, '2026-11-11' => 1.4235], $rates);
        Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), FrankfurterRateSource::BASE_URL.'/2026-11-10..2026-11-14?')
            && $request['base'] === 'USD'
            && $request['symbols'] === 'CAD');
    }

    public function test_frankfurter_throws_on_an_error_response(): void
    {
        Http::fake([FrankfurterRateSource::BASE_URL.'/*' => Http::response('', 500)]);

        $this->expectException(ExchangeRateException::class);
        $this->expectExceptionMessage('HTTP 500');

        (new FrankfurterRateSource)->fetch('USD', 'CAD', now()->subDay(), now());
    }

    public function test_yahoo_returns_daily_closes_keyed_by_date(): void
    {
        Http::fake([
            YahooFinanceRateSource::BASE_URL.'*' => Http::response([
                'chart' => [
                    'result' => [[
                        'meta' => ['currency' => 'CAD', 'symbol' => 'USDCAD=X', 'exchangeTimezoneName' => 'Europe/London'],
                        'timestamp' => [1762732800, 1762819200, 1762905600],
                        'indicators' => ['quote' => [['close' => [1.404379963874817, null, 1.4015799760818481]]]],
                    ]],
                    'error' => null,
                ],
            ]),
        ]);

        $rates = (new YahooFinanceRateSource)->fetch('usd', 'cad', Carbon::parse('2025-11-10'), Carbon::parse('2025-11-12'));

        $this->assertSame(['2025-11-10' => 1.4043799639, '2025-11-12' => 1.4015799761], $rates);
        Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), YahooFinanceRateSource::BASE_URL.'USDCAD=X?')
            && $request['interval'] === '1d'
            && $request->hasHeader('User-Agent'));
    }

    public function test_yahoo_throws_when_it_returns_no_data(): void
    {
        Http::fake([
            YahooFinanceRateSource::BASE_URL.'*' => Http::response([
                'chart' => ['result' => null, 'error' => ['code' => 'Not Found', 'description' => 'No data found, symbol may be delisted']],
            ]),
        ]);

        $this->expectException(ExchangeRateException::class);
        $this->expectExceptionMessage('symbol may be delisted');

        (new YahooFinanceRateSource)->fetch('USD', 'XYZ', now()->subDay(), now());
    }
}
