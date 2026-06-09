<?php

namespace Tests\Feature\Rules;

use App\Rules\StoreUrl;
use App\Services\Helpers\SettingsHelper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Once;
use Tests\TestCase;

class StoreUrlTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        SettingsHelper::$settings = null;
        Cache::flush();
        Once::flush();
    }

    private function enableHealing(): void
    {
        SettingsHelper::setSetting('integrated_services', ['ai' => [
            'enabled' => true,
            'default_provider_id' => 'p1',
            'providers' => [[
                'id' => 'p1', 'name' => 'Local', 'type' => 'ollama',
                'base_url' => 'http://ai.example:11434', 'model' => 'm',
            ]],
        ]]);
        SettingsHelper::$settings = null;
        Cache::flush();
        Once::flush();
    }

    /**
     * @return array<int, string>
     */
    private function runRule(string $url, bool $createStore): array
    {
        $failures = [];
        $fail = function (string $message) use (&$failures): void {
            $failures[] = $message;
        };

        (new StoreUrl)->setData(['data' => ['create_store' => $createStore]])->validate('url', $url, $fail);

        return $failures;
    }

    public function test_rejects_unknown_domain_when_healing_disabled(): void
    {
        $failures = $this->runRule('https://unknown.test/p', createStore: false);

        $this->assertContains('The domain does not belong to any stores', $failures);
    }

    public function test_defers_unknown_domain_when_healing_enabled(): void
    {
        $this->enableHealing();

        $failures = $this->runRule('https://unknown.test/p', createStore: false);

        $this->assertSame([], $failures);
    }

    public function test_still_rejects_malformed_url_when_healing_enabled(): void
    {
        $this->enableHealing();

        $failures = $this->runRule('not-a-url', createStore: true);

        $this->assertNotEmpty($failures);
    }
}
