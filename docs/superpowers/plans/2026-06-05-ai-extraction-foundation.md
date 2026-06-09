# AI Extraction Foundation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Stand up the `laravel/ai` SDK behind a settings-driven `AiService`, and ship one working AI operation — extraction fallback — via `AiExtractionService`, plus the Gemini provider and encrypted API keys.

**Architecture:** `AiService` is the single orchestration seam over the SDK; it reads the DB-backed AI settings (`IntegrationHelper`), injects provider credentials into the SDK's runtime config, and runs a small named `ConfiguredStructuredAgent` (subclass of the SDK's `StructuredAnonymousAgent`) that carries temperature/max-tokens from settings. `AiExtractionService` supplies instructions + JSON schema + preprocessed HTML and maps the result to a DTO; it never touches the SDK.

**Tech Stack:** Laravel 12, PHP 8.4, `laravel/ai` ^0.7, Filament 3, Spatie Laravel Settings, Pest 3.

**Spec:** `docs/superpowers/specs/2026-06-05-ai-extraction-foundation-design.md`

**Conventions:**
- Run all tooling through Lando: `lando artisan ...`, `lando composer ...`.
- Run tests with `lando artisan test --parallel --filter=...` (the test DB needs the full Lando stack up; if you see `getaddrinfo for tests_db failed`, run `lando start`).
- After PHP changes: `lando ssh -c "vendor/bin/pint --dirty"` then `lando phpcs` (PHPStan).
- DTOs are plain promoted-constructor classes in `app/Dto`. Services live in `app/Services` with a `static new()` factory using `resolve()`.
- Branch: `feature/priceghost-parity-ai` (already checked out).

---

## File Structure

New files:
- `app/Services/Ai/ConfiguredStructuredAgent.php` — SDK agent subclass carrying generation options from settings.
- `app/Dto/AiExtractionResultDto.php` — typed extraction result.
- `app/Services/AiService.php` — settings ↔ SDK bridge; `isEnabled()`, `structured()`, `testConnection()`.
- `app/Services/AiExtractionService.php` — `extract()` + `prepareHtml()`.
- `app/Filament/Actions/Integrations/TestAiConnectionAction.php` — settings "Test connection" action.
- `config/ai.php` — published SDK config (config only).
- `tests/Unit/Services/Ai/ConfiguredStructuredAgentTest.php`
- `tests/Feature/Services/AiServiceTest.php`
- `tests/Feature/Services/AiExtractionServiceTest.php`

Modified files:
- `composer.json` / `composer.lock` — add `laravel/ai`.
- `app/Enums/AiProvider.php` — add `Gemini`, `toLab()`, `driver()`.
- `tests/Unit/Enums/AiProviderTest.php` — cover `Gemini` + mappings.
- `app/Filament/Pages/AppSettingsPage.php` — Gemini fields, key encryption, Test action.

---

## Task 1: Install the laravel/ai SDK + publish config

**Files:**
- Modify: `composer.json`, `composer.lock`
- Create: `config/ai.php` (published)

- [ ] **Step 1: Require the package**

Run:
```bash
lando composer require laravel/ai
```
Expected: composer resolves `laravel/ai` (^0.7.x) with no conflict on `illuminate/*: ^12`. If it reports a platform/version conflict, stop and report — do not force.

- [ ] **Step 2: Publish config only (no conversation migrations)**

Run:
```bash
lando artisan vendor:publish --tag=ai-config --no-interaction
```
Expected: creates `config/ai.php`. Confirm no new files appear under `database/migrations` (the `agent_conversations` tables must NOT be published):
```bash
git status --short database/migrations
```
Expected: empty output.

- [ ] **Step 3: Sanity-check the SDK autoloads**

Run:
```bash
lando artisan tinker --execute 'echo \Laravel\Ai\Enums\Lab::Anthropic->value;'
```
Expected: prints `anthropic` (confirms the package is installed and the `Lab` enum exists).

- [ ] **Step 4: Commit**

```bash
git add composer.json composer.lock config/ai.php
git commit -m "chore: install laravel/ai SDK and publish config"
```

---

## Task 2: Add Gemini provider + SDK mapping to AiProvider enum

**Files:**
- Modify: `app/Enums/AiProvider.php`
- Test: `tests/Unit/Enums/AiProviderTest.php`

The current enum (from the cherry-picked scaffolding):
```php
<?php

namespace App\Enums;

enum AiProvider: string
{
    case OpenAI = 'openai';
    case Anthropic = 'anthropic';
    case Ollama = 'ollama';
}
```

- [ ] **Step 1: Read the existing test to match its style**

Run:
```bash
lando ssh -c "cat tests/Unit/Enums/AiProviderTest.php"
```
Note the existing assertions so the additions match the file's conventions (Pest `it()`/`test()` style, existing cases asserted).

- [ ] **Step 2: Write failing tests for Gemini + mappings**

Add these tests to `tests/Unit/Enums/AiProviderTest.php` (inside the existing file, matching its style):

```php
use App\Enums\AiProvider;
use Laravel\Ai\Enums\Lab;

it('includes the gemini provider', function () {
    expect(AiProvider::tryFrom('gemini'))->toBe(AiProvider::Gemini);
});

it('maps each provider to the matching SDK lab', function () {
    expect(AiProvider::OpenAI->toLab())->toBe(Lab::OpenAI)
        ->and(AiProvider::Anthropic->toLab())->toBe(Lab::Anthropic)
        ->and(AiProvider::Ollama->toLab())->toBe(Lab::Ollama)
        ->and(AiProvider::Gemini->toLab())->toBe(Lab::Gemini);
});

it('exposes the config driver key for each provider', function () {
    expect(AiProvider::OpenAI->driver())->toBe('openai')
        ->and(AiProvider::Anthropic->driver())->toBe('anthropic')
        ->and(AiProvider::Ollama->driver())->toBe('ollama')
        ->and(AiProvider::Gemini->driver())->toBe('gemini');
});
```

- [ ] **Step 3: Run tests to verify they fail**

Run:
```bash
lando artisan test --parallel --filter=AiProvider
```
Expected: FAIL — `Gemini` case and `toLab()`/`driver()` methods do not exist yet.

- [ ] **Step 4: Implement the enum changes**

Replace `app/Enums/AiProvider.php` with:
```php
<?php

namespace App\Enums;

use Laravel\Ai\Enums\Lab;

enum AiProvider: string
{
    case OpenAI = 'openai';
    case Anthropic = 'anthropic';
    case Ollama = 'ollama';
    case Gemini = 'gemini';

    /**
     * Map this provider to the Laravel AI SDK provider enum.
     */
    public function toLab(): Lab
    {
        return match ($this) {
            self::OpenAI => Lab::OpenAI,
            self::Anthropic => Lab::Anthropic,
            self::Ollama => Lab::Ollama,
            self::Gemini => Lab::Gemini,
        };
    }

    /**
     * The config key for this provider under `config('ai.providers.*')`.
     */
    public function driver(): string
    {
        return $this->value;
    }
}
```

- [ ] **Step 5: Run tests to verify they pass**

Run:
```bash
lando artisan test --parallel --filter=AiProvider
```
Expected: PASS (existing tests + the three new ones).

- [ ] **Step 6: Format + commit**

```bash
lando ssh -c "vendor/bin/pint --dirty"
git add app/Enums/AiProvider.php tests/Unit/Enums/AiProviderTest.php
git commit -m "feat: add Gemini provider and SDK lab mapping to AiProvider"
```

---

## Task 3: ConfiguredStructuredAgent (generation options from settings)

**Files:**
- Create: `app/Services/Ai/ConfiguredStructuredAgent.php`
- Test: `tests/Unit/Services/Ai/ConfiguredStructuredAgentTest.php`

This subclass exists so anonymous-style structured agents can carry `temperature`/`maxTokens`/`topP`, which the SDK reads via `method_exists` in `TextGenerationOptions::forAgent()`.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Services/Ai/ConfiguredStructuredAgentTest.php`:
```php
<?php

use App\Services\Ai\ConfiguredStructuredAgent;
use Illuminate\Contracts\JsonSchema\JsonSchema;

it('exposes injected generation options via methods', function () {
    $agent = new ConfiguredStructuredAgent(
        instructions: 'Extract data.',
        schema: fn (JsonSchema $schema) => ['price' => $schema->number()->required()],
        temperature: 0.1,
        maxTokens: 1500,
    );

    expect($agent->instructions())->toBe('Extract data.')
        ->and($agent->temperature())->toBe(0.1)
        ->and($agent->maxTokens())->toBe(1500);
});

it('returns null for unset generation options so the provider default is used', function () {
    $agent = new ConfiguredStructuredAgent(
        instructions: 'Extract data.',
        schema: fn (JsonSchema $schema) => ['price' => $schema->number()->required()],
    );

    expect($agent->temperature())->toBeNull()
        ->and($agent->maxTokens())->toBeNull();
});

it('builds the schema array from the provided closure', function () {
    $agent = new ConfiguredStructuredAgent(
        instructions: 'Extract data.',
        schema: fn (JsonSchema $schema) => ['price' => $schema->number()->required()],
    );

    $built = $agent->schema(app(JsonSchema::class));

    expect($built)->toHaveKey('price');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run:
```bash
lando artisan test --parallel --filter=ConfiguredStructuredAgent
```
Expected: FAIL — class does not exist.

- [ ] **Step 3: Implement the class**

Create `app/Services/Ai/ConfiguredStructuredAgent.php`:
```php
<?php

namespace App\Services\Ai;

use Closure;
use Laravel\Ai\StructuredAnonymousAgent;

/**
 * A structured anonymous agent that carries generation options (temperature,
 * max tokens, top-p) sourced from application settings. The Laravel AI SDK
 * resolves these via `method_exists()` in `TextGenerationOptions::forAgent()`,
 * so defining them as methods is how anonymous-style agents set generation options.
 */
class ConfiguredStructuredAgent extends StructuredAnonymousAgent
{
    public function __construct(
        string $instructions,
        Closure $schema,
        protected ?float $temperature = null,
        protected ?int $maxTokens = null,
        protected ?float $topP = null,
    ) {
        parent::__construct($instructions, [], [], $schema);
    }

    public function temperature(): ?float
    {
        return $this->temperature;
    }

    public function maxTokens(): ?int
    {
        return $this->maxTokens;
    }

    public function topP(): ?float
    {
        return $this->topP;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run:
```bash
lando artisan test --parallel --filter=ConfiguredStructuredAgent
```
Expected: PASS.

- [ ] **Step 5: Format + commit**

```bash
lando ssh -c "vendor/bin/pint --dirty"
git add app/Services/Ai/ConfiguredStructuredAgent.php tests/Unit/Services/Ai/ConfiguredStructuredAgentTest.php
git commit -m "feat: add ConfiguredStructuredAgent for settings-driven generation options"
```

---

## Task 4: AiExtractionResultDto

**Files:**
- Create: `app/Dto/AiExtractionResultDto.php`
- Test: covered indirectly in Task 6; add a tiny construction test here.
- Test: `tests/Unit/Dto/AiExtractionResultDtoTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Dto/AiExtractionResultDtoTest.php`:
```php
<?php

use App\Dto\AiExtractionResultDto;
use App\Enums\StockStatus;

it('holds extraction fields with sensible defaults', function () {
    $dto = new AiExtractionResultDto(
        title: 'Widget',
        price: 12.99,
        currency: 'USD',
        image: 'https://example.com/w.jpg',
        stockStatus: StockStatus::InStock,
        confidence: 0.8,
    );

    expect($dto->title)->toBe('Widget')
        ->and($dto->price)->toBe(12.99)
        ->and($dto->currency)->toBe('USD')
        ->and($dto->stockStatus)->toBe(StockStatus::InStock)
        ->and($dto->confidence)->toBe(0.8);
});

it('defaults every field', function () {
    $dto = new AiExtractionResultDto;

    expect($dto->title)->toBeNull()
        ->and($dto->price)->toBeNull()
        ->and($dto->stockStatus)->toBeNull()
        ->and($dto->confidence)->toBe(0.0);
});
```

> Before implementing, confirm the exact `StockStatus` case name for "in stock":
> ```bash
> lando ssh -c "cat app/Enums/StockStatus.php"
> ```
> If the case is not literally `InStock`, use the real case name in both the test and Task 6.

- [ ] **Step 2: Run test to verify it fails**

Run:
```bash
lando artisan test --parallel --filter=AiExtractionResultDto
```
Expected: FAIL — class does not exist.

- [ ] **Step 3: Implement the DTO**

Create `app/Dto/AiExtractionResultDto.php`:
```php
<?php

namespace App\Dto;

use App\Enums\StockStatus;

class AiExtractionResultDto
{
    public function __construct(
        public ?string $title = null,
        public ?float $price = null,
        public ?string $currency = null,
        public ?string $image = null,
        public ?StockStatus $stockStatus = null,
        public float $confidence = 0.0,
    ) {}
}
```

- [ ] **Step 4: Run test to verify it passes**

Run:
```bash
lando artisan test --parallel --filter=AiExtractionResultDto
```
Expected: PASS.

- [ ] **Step 5: Format + commit**

```bash
lando ssh -c "vendor/bin/pint --dirty"
git add app/Dto/AiExtractionResultDto.php tests/Unit/Dto/AiExtractionResultDtoTest.php
git commit -m "feat: add AiExtractionResultDto"
```

---

## Task 5: AiService (settings ↔ SDK bridge)

**Files:**
- Create: `app/Services/AiService.php`
- Test: `tests/Feature/Services/AiServiceTest.php`

`AiService` reads AI settings, resolves provider/model/timeout/temperature/max-tokens, injects credentials into runtime config, and runs `ConfiguredStructuredAgent`.

Settings array shape (under `integrated_services.ai`): `enabled`, `provider`, `default_model`, `timeout_seconds`, `max_tokens`, `temperature`, and per-provider `{openai,anthropic,gemini}.{base_url, api_key}` / `ollama.{base_url, model}`. API keys are stored encrypted (Task 7).

- [ ] **Step 1: Confirm existing IntegrationHelper API**

Run:
```bash
lando ssh -c "cat app/Services/Helpers/IntegrationHelper.php"
```
Confirm `getAiSettings(): array`, `isAiEnabled(): bool`, and `getAiProvider(): ?AiProvider` exist (they do, from the scaffolding). `AiService` uses these.

- [ ] **Step 2: Write the failing tests**

> Spatie settings persistence in tests: `SettingsHelper::setSetting()` calls
> `AppSettings::save()`, which needs the `settings` table migrated and the `app` group's
> rows to exist. If `setAiSettings()` throws `MissingSettings`, find how existing tests seed
> settings (`lando ssh -c "grep -rl 'SettingsHelper\|AppSettings\|integrated_services\|notification_services' tests"`)
> and mirror that setup (often a `beforeEach` seeding defaults, or `RefreshDatabase` + a settings seeder). Reuse it rather than inventing a new approach.

Create `tests/Feature/Services/AiServiceTest.php`:
```php
<?php

use App\Services\Ai\ConfiguredStructuredAgent;
use App\Services\AiService;
use App\Services\Helpers\IntegrationHelper;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Crypt;

/**
 * Helper: write AI settings into the Spatie settings store and clear caches so
 * IntegrationHelper re-reads them. IntegrationHelper::getAiSettings() wraps its read
 * in once(), and SettingsHelper memoises in a static — both must be cleared here.
 */
function setAiSettings(array $ai): void
{
    \App\Services\Helpers\SettingsHelper::setSetting('integrated_services', ['ai' => $ai]);
    \App\Services\Helpers\SettingsHelper::$settings = null;
    \Illuminate\Support\Facades\Cache::flush();
    \Illuminate\Support\Once::flush();
}

it('returns null from structured() when AI is disabled', function () {
    setAiSettings(['enabled' => false]);

    $result = AiService::new()->structured(
        'Extract.',
        fn (JsonSchema $schema) => ['price' => $schema->number()->required()],
        '<html>...</html>',
    );

    expect($result)->toBeNull();
});

it('returns the structured array when enabled and the agent is faked', function () {
    setAiSettings([
        'enabled' => true,
        'provider' => 'anthropic',
        'default_model' => 'claude-haiku-4-5-20251001',
        'timeout_seconds' => 30,
        'max_tokens' => 2000,
        'temperature' => 0.1,
        'anthropic' => ['api_key' => Crypt::encryptString('test-key')],
    ]);

    ConfiguredStructuredAgent::fake([
        ['price' => 19.99, 'currency' => 'USD'],
    ]);

    $result = AiService::new()->structured(
        'Extract.',
        fn (JsonSchema $schema) => [
            'price' => $schema->number()->required(),
            'currency' => $schema->string()->required(),
        ],
        '<html>...</html>',
    );

    expect($result)->toBe(['price' => 19.99, 'currency' => 'USD']);
    ConfiguredStructuredAgent::assertPrompted(fn ($prompt) => str_contains($prompt->prompt, 'html'));
});

it('returns null and logs when the SDK throws', function () {
    setAiSettings([
        'enabled' => true,
        'provider' => 'anthropic',
        'default_model' => 'claude-haiku-4-5-20251001',
        'anthropic' => ['api_key' => Crypt::encryptString('test-key')],
    ]);

    ConfiguredStructuredAgent::fake(function () {
        throw new \RuntimeException('boom');
    });

    $result = AiService::new()->structured(
        'Extract.',
        fn (JsonSchema $schema) => ['price' => $schema->number()->required()],
        '<html>...</html>',
    );

    expect($result)->toBeNull();
});
```

> Note on the fake data shape: when you pass an array of structured arrays to
> `ConfiguredStructuredAgent::fake([...])`, each becomes the structured payload returned by
> `->prompt()`. Verify this against the SDK's `FakeTextGateway` behaviour while implementing;
> if the fake expects `StructuredAgentResponse` objects instead of raw arrays, wrap them:
> `new \Laravel\Ai\Responses\StructuredAgentResponse(\Laravel\Ai\ulid(), ['price' => 19.99], '', new \Laravel\Ai\Responses\Data\Usage, new \Laravel\Ai\Responses\Data\Meta)`.
> Pick whichever the gateway accepts and keep the assertion (`->toBe([...])`) intact.

- [ ] **Step 3: Run tests to verify they fail**

Run:
```bash
lando artisan test --parallel --filter=AiServiceTest
```
Expected: FAIL — `AiService` does not exist.

- [ ] **Step 4: Implement AiService**

Create `app/Services/AiService.php`:
```php
<?php

namespace App\Services;

use App\Services\Ai\ConfiguredStructuredAgent;
use App\Services\Helpers\IntegrationHelper;
use Closure;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Throwable;

class AiService
{
    public static function new(): self
    {
        return resolve(static::class);
    }

    public function isEnabled(): bool
    {
        return IntegrationHelper::isAiEnabled();
    }

    /**
     * Run a structured prompt through the configured AI provider.
     *
     * @param  Closure  $schema  fn (Illuminate\Contracts\JsonSchema\JsonSchema $schema): array
     * @return array<string, mixed>|null
     */
    public function structured(string $instructions, Closure $schema, string $prompt): ?array
    {
        if (! $this->isEnabled()) {
            return null;
        }

        $provider = IntegrationHelper::getAiProvider();

        if ($provider === null) {
            return null;
        }

        $settings = IntegrationHelper::getAiSettings();

        $this->configureProviderCredentials($provider->driver(), $settings);

        $agent = new ConfiguredStructuredAgent(
            instructions: $instructions,
            schema: $schema,
            temperature: $this->floatOrNull($settings['temperature'] ?? null),
            maxTokens: $this->intOrNull($settings['max_tokens'] ?? null),
        );

        try {
            $response = $agent->prompt(
                $prompt,
                provider: $provider->toLab(),
                model: $this->resolveModel($provider->driver(), $settings),
                timeout: $this->intOrNull($settings['timeout_seconds'] ?? null),
            );

            return $response->toArray();
        } catch (Throwable $e) {
            Log::error('AI structured prompt failed: '.$e->getMessage());

            return null;
        }
    }

    /**
     * Probe the configured provider with a trivial prompt.
     *
     * @return true|string  true on success, an error message on failure
     */
    public function testConnection(): true|string
    {
        if (! $this->isEnabled()) {
            return 'AI is not enabled.';
        }

        $result = $this->structured(
            'Reply with the number 1.',
            fn (\Illuminate\Contracts\JsonSchema\JsonSchema $schema) => [
                'ok' => $schema->integer()->required(),
            ],
            'Return 1.',
        );

        return $result === null ? 'The AI provider did not return a valid response.' : true;
    }

    /**
     * Push the provider's decrypted key + base URL into the SDK runtime config.
     *
     * @param  array<string, mixed>  $settings
     */
    protected function configureProviderCredentials(string $driver, array $settings): void
    {
        $providerSettings = $settings[$driver] ?? [];

        $key = $this->decrypt($providerSettings['api_key'] ?? null);
        $url = $providerSettings['base_url'] ?? null;

        config(["ai.providers.{$driver}.key" => $key]);

        if (filled($url)) {
            config(["ai.providers.{$driver}.url" => $url]);
        }
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    protected function resolveModel(string $driver, array $settings): ?string
    {
        // Ollama configures its model name separately from the cloud default model.
        if ($driver === 'ollama' && filled($settings['ollama']['model'] ?? null)) {
            return $settings['ollama']['model'];
        }

        return $settings['default_model'] ?? null;
    }

    protected function decrypt(?string $value): ?string
    {
        if (blank($value)) {
            return null;
        }

        try {
            return Crypt::decryptString($value);
        } catch (Throwable) {
            // Defensive: tolerate any legacy plaintext value.
            return $value;
        }
    }

    protected function floatOrNull(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    protected function intOrNull(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }
}
```

- [ ] **Step 5: Run tests to verify they pass**

Run:
```bash
lando artisan test --parallel --filter=AiServiceTest
```
Expected: PASS. If the third test (SDK throws) does not return null, confirm the fake's throwing closure actually propagates through `prompt()`; if the fake swallows it, instead simulate failure by setting `enabled => true` but `provider => null`-producing settings is not valid here — keep the throwing-closure approach and adjust to the gateway's real exception path discovered in Step 2's note.

- [ ] **Step 6: Format + static analysis + commit**

```bash
lando ssh -c "vendor/bin/pint --dirty"
lando phpcs
git add app/Services/AiService.php tests/Feature/Services/AiServiceTest.php
git commit -m "feat: add AiService bridging AI settings to the laravel/ai SDK"
```

---

## Task 6: AiExtractionService (extraction fallback + HTML preprocessing)

**Files:**
- Create: `app/Services/AiExtractionService.php`
- Test: `tests/Feature/Services/AiExtractionServiceTest.php`

`AiExtractionService` depends on `AiService` (mocked in tests), preprocesses HTML, prompts for `{name, price, currency, imageUrl, stockStatus, confidence}`, and maps to `AiExtractionResultDto`.

- [ ] **Step 1: Confirm the helpers this task depends on**

Run:
```bash
lando ssh -c "grep -n 'function toFloat' app/Services/Helpers/CurrencyHelper.php; echo '---'; cat app/Enums/StockStatus.php"
```
Confirm `CurrencyHelper::toFloat()` exists and note the exact `StockStatus` case names (used in the availability mapping below). If a referenced case name differs, adjust the `match` in Step 4 and the test in Step 2 to the real names. The PriceGhost availability strings to map are the schema.org URLs (e.g. `https://schema.org/InStock`, `OutOfStock`).

- [ ] **Step 2: Write the failing tests**

Create `tests/Feature/Services/AiExtractionServiceTest.php`:
```php
<?php

use App\Dto\AiExtractionResultDto;
use App\Enums\StockStatus;
use App\Services\AiExtractionService;
use App\Services\AiService;

it('returns null when AI is disabled', function () {
    $this->mock(AiService::class, function ($mock) {
        $mock->shouldReceive('isEnabled')->andReturnFalse();
        $mock->shouldReceive('structured')->never();
    });

    $result = AiExtractionService::new()->extract('<html><body>Widget $12</body></html>');

    expect($result)->toBeNull();
});

it('maps a structured AI result to a DTO', function () {
    $this->mock(AiService::class, function ($mock) {
        $mock->shouldReceive('isEnabled')->andReturnTrue();
        $mock->shouldReceive('structured')->once()->andReturn([
            'name' => 'Widget',
            'price' => '12.99',
            'currency' => 'USD',
            'imageUrl' => 'https://example.com/w.jpg',
            'stockStatus' => 'https://schema.org/InStock',
            'confidence' => 0.82,
        ]);
    });

    $result = AiExtractionService::new()->extract('<html><body>Widget $12.99</body></html>');

    expect($result)->toBeInstanceOf(AiExtractionResultDto::class)
        ->and($result->title)->toBe('Widget')
        ->and($result->price)->toBe(12.99)
        ->and($result->currency)->toBe('USD')
        ->and($result->image)->toBe('https://example.com/w.jpg')
        ->and($result->stockStatus)->toBe(StockStatus::InStock)
        ->and($result->confidence)->toBe(0.82);
});

it('returns null when the AI result is empty', function () {
    $this->mock(AiService::class, function ($mock) {
        $mock->shouldReceive('isEnabled')->andReturnTrue();
        $mock->shouldReceive('structured')->once()->andReturnNull();
    });

    $result = AiExtractionService::new()->extract('<html></html>');

    expect($result)->toBeNull();
});

it('preprocesses html: strips scripts/styles and truncates to 25k chars', function () {
    $service = AiExtractionService::new();
    $html = '<html><head><style>.a{color:red}</style><script>alert(1)</script></head>'
        .'<body>Price: $12.99'.str_repeat('x', 40000).'</body></html>';

    $prepared = $service->prepareHtml($html);

    expect($prepared)->not->toContain('alert(1)')
        ->and($prepared)->not->toContain('color:red')
        ->and(strlen($prepared))->toBeLessThanOrEqual(25000);
});
```

- [ ] **Step 3: Run tests to verify they fail**

Run:
```bash
lando artisan test --parallel --filter=AiExtractionServiceTest
```
Expected: FAIL — `AiExtractionService` does not exist.

- [ ] **Step 4: Implement AiExtractionService**

Create `app/Services/AiExtractionService.php`:
```php
<?php

namespace App\Services;

use App\Dto\AiExtractionResultDto;
use App\Enums\StockStatus;
use App\Services\Helpers\CurrencyHelper;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class AiExtractionService
{
    protected const int MAX_HTML_CHARS = 25000;

    /**
     * The extraction-fallback instructions, ported from PriceGhost's EXTRACTION_PROMPT.
     */
    protected const string EXTRACTION_PROMPT = <<<'PROMPT'
        You are a precise e-commerce data extraction assistant. Extract the product's
        current, purchasable price and details from the provided HTML.

        Rules:
        - Return the single price a customer would pay right now to buy the product.
        - Ignore crossed-out, "was", RRP, savings amounts, bundle totals, and per-unit prices.
        - If multiple variants exist, choose the default/selected variant.
        - currency must be an ISO 4217 code (e.g. USD, GBP, EUR) if determinable.
        - stockStatus must be a schema.org availability URL if determinable
          (e.g. https://schema.org/InStock or https://schema.org/OutOfStock).
        - confidence is your certainty from 0 to 1.
        - Use null for any field you cannot determine.
        PROMPT;

    public function __construct(protected AiService $ai) {}

    public static function new(): self
    {
        return resolve(static::class);
    }

    public function extract(string $html, ?Collection $schemaOrg = null): ?AiExtractionResultDto
    {
        if (! $this->ai->isEnabled()) {
            return null;
        }

        $result = $this->ai->structured(
            self::EXTRACTION_PROMPT,
            fn (JsonSchema $schema) => [
                'name' => $schema->string(),
                'price' => $schema->number(),
                'currency' => $schema->string(),
                'imageUrl' => $schema->string(),
                'stockStatus' => $schema->string(),
                'confidence' => $schema->number()->min(0)->max(1)->required(),
            ],
            $this->prepareHtml($html, $schemaOrg),
        );

        if (blank($result)) {
            return null;
        }

        return new AiExtractionResultDto(
            title: $result['name'] ?? null,
            price: $this->parsePrice($result['price'] ?? null),
            currency: $result['currency'] ?? null,
            image: $result['imageUrl'] ?? null,
            stockStatus: $this->mapStockStatus($result['stockStatus'] ?? null),
            confidence: (float) ($result['confidence'] ?? 0.0),
        );
    }

    /**
     * Reduce HTML to the most price-relevant content within a token budget.
     * Port of PriceGhost's prepareHtmlForAI(): strip scripts/styles/meta and truncate.
     */
    public function prepareHtml(string $html, ?Collection $schemaOrg = null): string
    {
        $cleaned = preg_replace(
            ['#<script\b[^>]*>.*?</script>#is', '#<style\b[^>]*>.*?</style>#is', '#<meta\b[^>]*>#is'],
            '',
            $html,
        ) ?? $html;

        // Hoist any decoded schema.org JSON to the top so the model sees it first.
        if ($schemaOrg !== null && $schemaOrg->isNotEmpty()) {
            $cleaned = $schemaOrg->toJson().' '.$cleaned;
        }

        return Str::limit($cleaned, self::MAX_HTML_CHARS, '');
    }

    protected function parsePrice(mixed $price): ?float
    {
        if (blank($price)) {
            return null;
        }

        if (is_numeric($price)) {
            return (float) $price;
        }

        $parsed = CurrencyHelper::toFloat((string) $price);

        return $parsed !== null ? (float) $parsed : null;
    }

    protected function mapStockStatus(?string $availability): ?StockStatus
    {
        if (blank($availability)) {
            return null;
        }

        return str_contains(strtolower($availability), 'outofstock')
            ? StockStatus::OutOfStock
            : StockStatus::InStock;
    }
}
```

> Before running, verify two things against Step 1's output and adjust if needed:
> 1. `StockStatus::InStock` / `StockStatus::OutOfStock` are the real case names.
> 2. `CurrencyHelper::toFloat()` returns a numeric/`float` (cast accordingly).
> Also confirm the `JsonSchema` fluent methods used (`string()`, `number()->min()->max()`,
> `->required()`) match the SDK's `Illuminate\JsonSchema` API seen in `functions.php`; they do
> (`StringType`, `NumberType` with `minimum`/`maximum`), but adjust method names if PHPStan flags them.

- [ ] **Step 5: Run tests to verify they pass**

Run:
```bash
lando artisan test --parallel --filter=AiExtractionServiceTest
```
Expected: PASS.

- [ ] **Step 6: Format + static analysis + commit**

```bash
lando ssh -c "vendor/bin/pint --dirty"
lando phpcs
git add app/Services/AiExtractionService.php tests/Feature/Services/AiExtractionServiceTest.php
git commit -m "feat: add AiExtractionService extraction-fallback operation"
```

---

## Task 7: Encrypt API keys in the settings form

**Files:**
- Modify: `app/Filament/Pages/AppSettingsPage.php`
- Test: `tests/Feature/Services/AiServiceTest.php` (add a round-trip test)

Switch the three API-key fields (`openai`, `anthropic`, `gemini`) to encrypt-on-save, blank-on-load, persist-only-when-entered. Also add the Gemini provider option + fields (mirrors the existing per-provider blocks).

- [ ] **Step 1: Read the current AI settings method**

Run:
```bash
lando ssh -c "sed -n '/function getAiSettings/,/^    }/p' app/Filament/Pages/AppSettingsPage.php"
```
This is the block to edit. Note the existing `openai`/`anthropic`/`ollama` field definitions and the `Select::make('provider')` options.

- [ ] **Step 2: Add the encryption helper trait method**

In `app/Filament/Pages/AppSettingsPage.php`, add these imports near the top (if absent):
```php
use Illuminate\Support\Facades\Crypt;
```

- [ ] **Step 3: Add Gemini to the provider Select**

In `getAiSettings()`, update the provider options to include Gemini:
```php
Select::make('provider')
    ->label('Provider')
    ->options([
        AiProvider::OpenAI->value => 'OpenAI',
        AiProvider::Anthropic->value => 'Anthropic',
        AiProvider::Ollama->value => 'Ollama',
        AiProvider::Gemini->value => 'Gemini',
    ])
    ->required(fn (Get $get): bool => (bool) $get('enabled'))
    ->live(),
```

- [ ] **Step 4: Convert the api_key fields to the encryption pattern**

Replace the `openai.api_key` field with this pattern (and apply the same shape to `anthropic.api_key`, plus a new `gemini.api_key`):
```php
TextInput::make('openai.api_key')
    ->label('OpenAI API key')
    ->password()
    ->revealable()
    ->helperText('Leave blank to keep the current key.')
    ->dehydrated(fn (?string $state, Get $get): bool => filled($state) && $get('provider') === AiProvider::OpenAI->value)
    ->dehydrateStateUsing(fn (string $state): string => Crypt::encryptString($state))
    ->hidden(fn (Get $get): bool => $get('provider') !== AiProvider::OpenAI->value),
```

Add the Gemini block after the existing provider blocks:
```php
TextInput::make('gemini.base_url')
    ->label('Gemini base URL')
    ->placeholder('https://generativelanguage.googleapis.com/v1beta')
    ->url()
    ->dehydrated(fn (Get $get): bool => $get('provider') === AiProvider::Gemini->value)
    ->hidden(fn (Get $get): bool => $get('provider') !== AiProvider::Gemini->value),
TextInput::make('gemini.api_key')
    ->label('Gemini API key')
    ->password()
    ->revealable()
    ->helperText('Leave blank to keep the current key.')
    ->dehydrated(fn (?string $state, Get $get): bool => filled($state) && $get('provider') === AiProvider::Gemini->value)
    ->dehydrateStateUsing(fn (string $state): string => Crypt::encryptString($state))
    ->hidden(fn (Get $get): bool => $get('provider') !== AiProvider::Gemini->value),
```

Apply the identical `->password()->revealable()->helperText(...)->dehydrated(filled && provider)->dehydrateStateUsing(encrypt)` treatment to `anthropic.api_key`. Do NOT add `formatStateUsing` — the field must render blank on load so stored ciphertext is never shown or re-encrypted.

Also update the section description to mention Gemini:
```php
__('Configure AI provider settings (OpenAI, Anthropic, Gemini, Ollama)')
```

- [ ] **Step 5: Write a Filament feature test for the encryption round-trip**

Add to `tests/Feature/Services/AiServiceTest.php`:
```php
use App\Filament\Pages\AppSettingsPage;
use App\Models\User;

it('encrypts a newly entered api key and AiService reads it decrypted', function () {
    $this->actingAs(User::factory()->create(['is_admin' => true]));

    setAiSettings(['enabled' => true, 'provider' => 'anthropic', 'default_model' => 'claude-haiku-4-5-20251001']);

    livewire(AppSettingsPage::class)
        ->fillForm([
            'integrated_services.ai.enabled' => true,
            'integrated_services.ai.provider' => 'anthropic',
            'integrated_services.ai.default_model' => 'claude-haiku-4-5-20251001',
            'integrated_services.ai.anthropic.api_key' => 'sk-secret-123',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $stored = \App\Services\Helpers\IntegrationHelper::getAiSettings()['anthropic']['api_key'];

    expect($stored)->not->toBe('sk-secret-123')                       // stored ciphertext, not plaintext
        ->and(\Illuminate\Support\Facades\Crypt::decryptString($stored))->toBe('sk-secret-123');
});
```

> Verify the admin gate: check how other Filament page tests authenticate (e.g.
> `lando ssh -c "grep -rl 'AppSettingsPage\|SettingsPage' tests"`). Use the same user/role
> setup (the `is_admin` flag above is a guess — match the real `User` factory/state). Also
> confirm `livewire()` helper is available (it is, per CLAUDE.md Filament testing notes).

- [ ] **Step 6: Run the test to verify it fails, then passes**

Run:
```bash
lando artisan test --parallel --filter=AiServiceTest
```
Expected: the new round-trip test FAILS before Step 4's form changes are correct, PASSES after. Iterate on the form until green.

- [ ] **Step 7: Format + static analysis + commit**

```bash
lando ssh -c "vendor/bin/pint --dirty"
lando phpcs
git add app/Filament/Pages/AppSettingsPage.php tests/Feature/Services/AiServiceTest.php
git commit -m "feat: encrypt AI API keys and add Gemini provider settings"
```

---

## Task 8: Test connection action in settings

**Files:**
- Create: `app/Filament/Actions/Integrations/TestAiConnectionAction.php`
- Modify: `app/Filament/Pages/AppSettingsPage.php` (wire the action into the AI section)
- Test: `tests/Feature/Services/AiServiceTest.php` (action behaviour)

- [ ] **Step 1: Read an existing Test action to copy the pattern**

Run:
```bash
lando ssh -c "cat app/Filament/Actions/Notifications/TestGotifyAction.php"
```
Note its namespace style, how it resolves config/settings, and how it dispatches a Filament `Notification`. Mirror it. Confirm the directory `app/Filament/Actions/Integrations` is acceptable (create it); if the codebase prefers a flatter layout, place the file alongside the notification actions instead and adjust the namespace.

- [ ] **Step 2: Write the failing test**

Add to `tests/Feature/Services/AiServiceTest.php`:
```php
it('testConnection returns a message when AI is disabled', function () {
    setAiSettings(['enabled' => false]);

    expect(AiService::new()->testConnection())->toBeString();
});

it('testConnection returns true when the agent responds', function () {
    setAiSettings([
        'enabled' => true,
        'provider' => 'anthropic',
        'default_model' => 'claude-haiku-4-5-20251001',
        'anthropic' => ['api_key' => \Illuminate\Support\Facades\Crypt::encryptString('test-key')],
    ]);

    \App\Services\Ai\ConfiguredStructuredAgent::fake([['ok' => 1]]);

    expect(AiService::new()->testConnection())->toBeTrue();
});
```

- [ ] **Step 3: Run the test to verify it fails**

Run:
```bash
lando artisan test --parallel --filter=AiServiceTest
```
Expected: FAIL if `testConnection()` was not finished in Task 5 — it was, so these may already pass. If they pass, that confirms `testConnection()`; proceed to wire the UI action. If they fail, fix `testConnection()` to match.

- [ ] **Step 4: Implement the Filament action**

Create `app/Filament/Actions/Integrations/TestAiConnectionAction.php` (mirroring `TestGotifyAction`'s structure — adjust the parent class/namespace to whatever `TestGotifyAction` extends):
```php
<?php

namespace App\Filament\Actions\Integrations;

use App\Services\AiService;
use Filament\Forms\Components\Actions\Action;
use Filament\Notifications\Notification;

class TestAiConnectionAction
{
    public static function make(): Action
    {
        return Action::make('testAiConnection')
            ->label('Test connection')
            ->action(function (): void {
                $result = AiService::new()->testConnection();

                if ($result === true) {
                    Notification::make()
                        ->title('AI connection succeeded')
                        ->success()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title('AI connection failed')
                    ->body($result)
                    ->danger()
                    ->send();
            });
    }
}
```

> Match the action base class to `TestGotifyAction`. If that action is a `Filament\Actions\Action`
> (header action) rather than a form-components action, use the same import here and attach it
> accordingly in Step 5. Save the AI settings before testing — the action reads persisted
> settings, so either rely on the user saving first (note it in the field helper text) or call
> `$livewire->save()` in the action; keep it simple and document "Save before testing".

- [ ] **Step 5: Wire the action into the AI section**

In `getAiSettings()`, attach the action to a field (commonly a hint action on the `enabled` toggle or a standalone `Actions` component). Mirror however `TestGotifyAction` is attached in the Gotify section:
```php
// e.g. as a field-level hint action:
->hintAction(\App\Filament\Actions\Integrations\TestAiConnectionAction::make())
```
Find the Gotify attachment for the exact idiom:
```bash
lando ssh -c "grep -n 'TestGotifyAction' app/Filament/Pages/AppSettingsPage.php"
```
and copy that placement for the AI section.

- [ ] **Step 6: Run tests + a manual smoke check**

Run:
```bash
lando artisan test --parallel --filter=AiServiceTest
```
Expected: PASS.

Then load the settings page in the browser (Playwright MCP if available, base URL `http://price-buddy.lndo.site/admin`, login `test@test.com` / `password`) and confirm: the AI section shows the provider Select with Gemini, the API-key fields render blank with a reveal toggle, and the "Test connection" button appears. Screenshot for evidence.

- [ ] **Step 7: Format + static analysis + commit**

```bash
lando ssh -c "vendor/bin/pint --dirty"
lando phpcs
git add app/Filament/Actions/Integrations/TestAiConnectionAction.php app/Filament/Pages/AppSettingsPage.php tests/Feature/Services/AiServiceTest.php
git commit -m "feat: add AI test-connection action to settings"
```

---

## Task 9: Full verification sweep

**Files:** none (verification only)

- [ ] **Step 1: Run the entire test suite in parallel**

Run:
```bash
lando artisan test --parallel
```
Expected: all green. Investigate and fix any regression before proceeding.

- [ ] **Step 2: Coding standards + static analysis**

Run:
```bash
lando phpcs-fix && lando phpcs
```
Expected: no fixes needed (or only auto-fixes), PHPStan reports no errors.

- [ ] **Step 3: Confirm no stray conversation migrations or plaintext keys**

Run:
```bash
git status --short database/migrations
lando artisan tinker --execute 'echo json_encode(\App\Services\Helpers\IntegrationHelper::getAiSettings());'
```
Expected: no new migration files; any stored `api_key` value is ciphertext (not readable plaintext).

- [ ] **Step 4: Final summary commit (if anything uncommitted) + branch review**

```bash
git log --oneline main..HEAD
```
Expected: a clean sequence of the feature commits from Tasks 1–8.

---

## Self-Review Notes (for the implementer)

- **Temperature/max-tokens** are delivered via `ConfiguredStructuredAgent` methods (Task 3), not `prompt()` args — verified against the SDK's `TextGenerationOptions::forAgent()`.
- **Faking** uses the per-class `ConfiguredStructuredAgent::fake()` seam; `AiExtractionService` tests mock `AiService` directly. If the fake API for structured arrays differs from the assumption, Task 5 Step 2's note tells you how to adapt.
- **Encryption** never loads the stored key back into the form (no `formatStateUsing`) and only persists when a new value is typed — this is what prevents double-encryption.
- **Out of scope (next slices):** verify/arbitrate/stock operations, scrape-pipeline wiring, per-product overrides, voting/selection modal.
