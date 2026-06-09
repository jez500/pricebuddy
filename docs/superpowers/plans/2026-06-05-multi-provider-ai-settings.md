# Multiple AI Providers Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let users configure several AI providers in Settings → AI and choose one as the default (active) provider, replacing the single-provider config.

**Architecture:** A `providers[]` + `default_provider_id` settings structure, resolved through a typed `AiProviderConfigDto` so `AiService` and the rest depend on the *active provider*, not the storage shape. A Spatie settings migration auto-converts the existing single-provider config. The Filament UI becomes a `Repeater` of providers with per-row Test + Ollama model refresh, and a default-provider select.

**Tech Stack:** Laravel 12, PHP 8.4, Filament 3.3, Spatie Laravel Settings, laravel/ai 0.7, Pest 3.

**Spec:** `docs/superpowers/specs/2026-06-05-multi-provider-ai-settings-design.md`

**Conventions:**
- All tooling via Lando: `lando artisan ...`, `lando ssh -c "..."`. Never run php on the host.
- Tests: `lando artisan test --parallel --filter=...`. If a test fails with `getaddrinfo for tests_db failed`, run `lando start`.
- After PHP edits run `lando ssh -c "vendor/bin/pint --dirty"` then the FULL `lando phpcs` and confirm it ends with `[OK] No errors`. GOTCHA: `lando phpcs` runs Pint then PHPStan; a Pint failure short-circuits before PHPStan, so a passing Pint line is not enough — confirm `[OK] No errors`.
- Branch: `feature/priceghost-parity-ai` (already checked out). End commit messages with a blank line then `Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>`.

---

## File Structure

New:
- `app/Dto/AiProviderConfigDto.php` — typed active-provider config.
- `app/Settings/AiProvidersRestructure.php` — pure `transform()` used by the migration (testable).
- `database/settings/2026_06_05_000000_restructure_ai_providers.php` — settings migration.
- `tests/Unit/Dto/AiProviderConfigDtoTest.php`
- `tests/Unit/Settings/AiProvidersRestructureTest.php`

Modified:
- `app/Services/Helpers/IntegrationHelper.php` — resolver methods; new-shape `isAiEnabled`; remove `getAiProvider`.
- `app/Services/AiService.php` — DTO-driven; `testProviderConfig`.
- `app/Filament/Pages/AppSettingsPage.php` — Repeater UI, default select, array encryption, per-row Ollama state, `refreshOllamaModelsFor()` + `testProviderById()` methods; remove the `TestAiConnectionAction` usage.
- `tests/Unit/Services/IntegrationHelperTest.php`, `tests/Feature/Services/AiServiceTest.php`, `tests/Feature/Filament/AppSettingsAiEncryptionTest.php`, `tests/Feature/Filament/AppSettingsOllamaModelsTest.php`
- Delete: `app/Filament/Actions/Integrations/TestAiConnectionAction.php` (replaced by the per-row test).

---

## Task 1: AiProviderConfigDto

**Files:**
- Create: `app/Dto/AiProviderConfigDto.php`
- Test: `tests/Unit/Dto/AiProviderConfigDtoTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Dto/AiProviderConfigDtoTest.php`:
```php
<?php

use App\Dto\AiProviderConfigDto;
use App\Enums\AiProvider;

it('builds from an array, mapping the type to the enum', function () {
    $dto = AiProviderConfigDto::fromArray([
        'id' => 'p1',
        'name' => 'Claude',
        'type' => 'anthropic',
        'model' => 'claude-haiku-4-5-20251001',
        'base_url' => 'https://api.anthropic.com',
        'api_key' => 'cipher',
        'timeout_seconds' => 45,
        'max_tokens' => 1500,
        'temperature' => 0.3,
    ]);

    expect($dto)->toBeInstanceOf(AiProviderConfigDto::class)
        ->and($dto->id)->toBe('p1')
        ->and($dto->name)->toBe('Claude')
        ->and($dto->type)->toBe(AiProvider::Anthropic)
        ->and($dto->model)->toBe('claude-haiku-4-5-20251001')
        ->and($dto->baseUrl)->toBe('https://api.anthropic.com')
        ->and($dto->apiKey)->toBe('cipher')
        ->and($dto->timeoutSeconds)->toBe(45)
        ->and($dto->maxTokens)->toBe(1500)
        ->and($dto->temperature)->toBe(0.3);
});

it('applies defaults for missing generation params', function () {
    $dto = AiProviderConfigDto::fromArray(['id' => 'p1', 'type' => 'ollama']);

    expect($dto->timeoutSeconds)->toBe(60)
        ->and($dto->maxTokens)->toBe(2000)
        ->and($dto->temperature)->toBe(0.2)
        ->and($dto->name)->toBe('Ollama'); // falls back to the enum case name
});

it('returns null for an unknown or missing type', function () {
    expect(AiProviderConfigDto::fromArray(['id' => 'p1', 'type' => 'nope']))->toBeNull()
        ->and(AiProviderConfigDto::fromArray(['id' => 'p1']))->toBeNull();
});

it('returns null when the id is blank', function () {
    expect(AiProviderConfigDto::fromArray(['id' => '', 'type' => 'ollama']))->toBeNull();
});
```

- [ ] **Step 2: Run, confirm FAIL**

Run: `lando artisan test --parallel --filter=AiProviderConfigDto`
Expected: FAIL (class missing).

- [ ] **Step 3: Implement**

Create `app/Dto/AiProviderConfigDto.php`:
```php
<?php

namespace App\Dto;

use App\Enums\AiProvider;

class AiProviderConfigDto
{
    public function __construct(
        public string $id,
        public string $name,
        public AiProvider $type,
        public ?string $model = null,
        public ?string $baseUrl = null,
        public ?string $apiKey = null,
        public int $timeoutSeconds = 60,
        public int $maxTokens = 2000,
        public float $temperature = 0.2,
    ) {}

    /**
     * Build a DTO from a stored provider array, or null if it is not usable.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): ?self
    {
        $type = AiProvider::tryFrom((string) ($data['type'] ?? ''));

        if ($type === null || blank($data['id'] ?? null)) {
            return null;
        }

        return new self(
            id: (string) $data['id'],
            name: filled($data['name'] ?? null) ? (string) $data['name'] : $type->name,
            type: $type,
            model: $data['model'] ?? null,
            baseUrl: $data['base_url'] ?? null,
            apiKey: $data['api_key'] ?? null,
            timeoutSeconds: (int) ($data['timeout_seconds'] ?? 60),
            maxTokens: (int) ($data['max_tokens'] ?? 2000),
            temperature: (float) ($data['temperature'] ?? 0.2),
        );
    }
}
```

- [ ] **Step 4: Run, confirm PASS**

Run: `lando artisan test --parallel --filter=AiProviderConfigDto`
Expected: PASS (4 tests).

- [ ] **Step 5: Format + commit**

```bash
lando ssh -c "vendor/bin/pint --dirty"
git add app/Dto/AiProviderConfigDto.php tests/Unit/Dto/AiProviderConfigDtoTest.php
git commit -m "feat: add AiProviderConfigDto resolver DTO"
```

---

## Task 2: Backend migration — IntegrationHelper resolver + AiService (new shape)

This task switches the backend from the single-provider shape to the `providers[]` + `default_provider_id` shape. IntegrationHelper, AiService, and BOTH their test files change together so the suite stays green at the commit.

**Files:**
- Modify: `app/Services/Helpers/IntegrationHelper.php`
- Modify: `app/Services/AiService.php`
- Modify: `tests/Unit/Services/IntegrationHelperTest.php`
- Modify: `tests/Feature/Services/AiServiceTest.php`

- [ ] **Step 1: Read the current files**

```bash
lando ssh -c "cat app/Services/Helpers/IntegrationHelper.php"
lando ssh -c "cat app/Services/AiService.php"
lando ssh -c "cat tests/Unit/Services/IntegrationHelperTest.php"
```
Note: `IntegrationHelper` currently has `getAiSettings()`, `isAiEnabled()`, `getAiProvider()`. `AiService` currently uses `getAiProvider()` + reads `$settings['temperature']` etc. and has `configureProviderCredentials(string $driver, array $settings)`, `resolveModel()`, `floatOrNull()`, `intOrNull()`, `decrypt()`. You will replace those with DTO-driven equivalents.

- [ ] **Step 2: Rewrite IntegrationHelperTest to the new shape (failing)**

Replace the AI-related tests in `tests/Unit/Services/IntegrationHelperTest.php`. Keep the file's existing class/imports/setup; replace the AI assertions with (adapt to the file's existing seeding helper — it sets `integrated_services` via the settings store; mirror it):
```php
public function test_get_ai_providers_maps_entries_to_dtos(): void
{
    $this->setIntegratedServices(['ai' => [
        'enabled' => true,
        'default_provider_id' => 'p1',
        'providers' => [
            ['id' => 'p1', 'name' => 'Claude', 'type' => 'anthropic', 'model' => 'm'],
            ['id' => 'p2', 'name' => 'Local', 'type' => 'ollama', 'model' => 'gemma4:e4b'],
            ['id' => 'p3', 'name' => 'Bad', 'type' => 'nonsense'], // skipped
        ],
    ]]);

    $providers = \App\Services\Helpers\IntegrationHelper::getAiProviders();

    $this->assertCount(2, $providers);
    $this->assertSame('p1', $providers[0]->id);
    $this->assertSame(\App\Enums\AiProvider::Ollama, $providers[1]->type);
}

public function test_get_active_ai_provider_selects_by_default_id(): void
{
    $this->setIntegratedServices(['ai' => [
        'enabled' => true,
        'default_provider_id' => 'p2',
        'providers' => [
            ['id' => 'p1', 'name' => 'A', 'type' => 'anthropic'],
            ['id' => 'p2', 'name' => 'B', 'type' => 'ollama'],
        ],
    ]]);

    $active = \App\Services\Helpers\IntegrationHelper::getActiveAiProvider();

    $this->assertNotNull($active);
    $this->assertSame('p2', $active->id);
}

public function test_get_active_ai_provider_is_null_when_default_points_nowhere(): void
{
    $this->setIntegratedServices(['ai' => [
        'enabled' => true,
        'default_provider_id' => 'gone',
        'providers' => [['id' => 'p1', 'name' => 'A', 'type' => 'anthropic']],
    ]]);

    $this->assertNull(\App\Services\Helpers\IntegrationHelper::getActiveAiProvider());
}

public function test_is_ai_enabled_requires_enabled_and_a_valid_default(): void
{
    $this->setIntegratedServices(['ai' => [
        'enabled' => true,
        'default_provider_id' => 'p1',
        'providers' => [['id' => 'p1', 'name' => 'A', 'type' => 'anthropic']],
    ]]);
    $this->assertTrue(\App\Services\Helpers\IntegrationHelper::isAiEnabled());

    $this->setIntegratedServices(['ai' => [
        'enabled' => false,
        'default_provider_id' => 'p1',
        'providers' => [['id' => 'p1', 'name' => 'A', 'type' => 'anthropic']],
    ]]);
    $this->assertFalse(\App\Services\Helpers\IntegrationHelper::isAiEnabled());
}

public function test_get_ai_settings_is_empty_array_without_settings(): void
{
    $this->assertSame([], \App\Services\Helpers\IntegrationHelper::getAiSettings());
}
```
If the file lacks a `setIntegratedServices()` helper, add one mirroring how the existing test wrote settings (it used `SettingsHelper::setSetting('integrated_services', ...)` + cache/once flush). Remove any test that references the deleted `getAiProvider()` method.

Run: `lando artisan test --parallel --filter=IntegrationHelperTest` → FAIL (methods missing).

- [ ] **Step 3: Implement the IntegrationHelper resolver**

In `app/Services/Helpers/IntegrationHelper.php`: add `use App\Dto\AiProviderConfigDto;`. Replace `isAiEnabled()` and `getAiProvider()` with:
```php
    public static function isAiEnabled(): bool
    {
        return (bool) data_get(self::getAiSettings(), 'enabled', false)
            && self::getActiveAiProvider() !== null;
    }

    /**
     * @return array<int, AiProviderConfigDto>
     */
    public static function getAiProviders(): array
    {
        return collect(data_get(self::getAiSettings(), 'providers', []))
            ->map(fn ($provider) => is_array($provider) ? AiProviderConfigDto::fromArray($provider) : null)
            ->filter()
            ->values()
            ->all();
    }

    public static function getActiveAiProvider(): ?AiProviderConfigDto
    {
        $defaultId = data_get(self::getAiSettings(), 'default_provider_id');

        if (blank($defaultId)) {
            return null;
        }

        foreach (self::getAiProviders() as $provider) {
            if ($provider->id === $defaultId) {
                return $provider;
            }
        }

        return null;
    }
```
Delete the old `getAiProvider()` method and remove the now-unused `use App\Enums\AiProvider;` import if nothing else uses it (check first).

- [ ] **Step 4: Run IntegrationHelperTest, confirm PASS**

Run: `lando artisan test --parallel --filter=IntegrationHelperTest`
Expected: PASS. (AiServiceTest is still red until Step 6 — that's expected; do not run the full suite yet.)

- [ ] **Step 5: Rewrite AiService to be DTO-driven**

Replace the body of `app/Services/AiService.php` with (keeping the namespace + `new()`):
```php
<?php

namespace App\Services;

use App\Dto\AiProviderConfigDto;
use App\Enums\AiProvider;
use App\Services\Ai\ConfiguredStructuredAgent;
use App\Services\Helpers\IntegrationHelper;
use Closure;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Responses\StructuredAgentResponse;
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
     * Run a structured prompt through the active (default) AI provider.
     *
     * @param  Closure(JsonSchema): array<string, mixed>  $schema
     * @return array<string, mixed>|null
     */
    public function structured(string $instructions, Closure $schema, string $prompt): ?array
    {
        $provider = IntegrationHelper::getActiveAiProvider();

        if (! $this->isEnabled() || $provider === null) {
            return null;
        }

        return $this->runStructuredFor($provider, $instructions, $schema, $prompt);
    }

    /**
     * Run a structured prompt against a specific provider config.
     *
     * @param  Closure(JsonSchema): array<string, mixed>  $schema
     * @return array<string, mixed>|null
     */
    protected function runStructuredFor(AiProviderConfigDto $provider, string $instructions, Closure $schema, string $prompt): ?array
    {
        $this->configureProviderCredentials($provider);

        $agent = new ConfiguredStructuredAgent(
            instructions: $instructions,
            schema: $schema,
            temperature: $provider->temperature,
            maxTokens: $provider->maxTokens,
        );

        try {
            $response = $agent->prompt(
                $prompt,
                provider: $provider->type->toLab(),
                model: $provider->model,
                timeout: $provider->timeoutSeconds,
            );

            return $response instanceof StructuredAgentResponse ? $response->toArray() : null;
        } catch (Throwable $e) {
            Log::error('AI structured prompt failed.', [
                'provider' => $provider->type->driver(),
                'exception' => $e::class,
            ]);

            return null;
        }
    }

    /**
     * Probe the active provider's connection.
     *
     * @return true|string true on success, an error message on failure
     */
    public function testConnection(): true|string
    {
        if (! $this->isEnabled()) {
            return 'AI is not enabled.';
        }

        $provider = IntegrationHelper::getActiveAiProvider();

        if ($provider === null) {
            return 'No AI provider is configured.';
        }

        return $this->testProviderConfig($provider);
    }

    /**
     * Probe a specific provider config.
     *
     * @return true|string true on success, an error message on failure
     */
    public function testProviderConfig(AiProviderConfigDto $provider): true|string
    {
        // Ollama models can take ~1 minute to cold-load; verify reachability + model
        // presence via /api/tags instead of running a generation.
        if ($provider->type === AiProvider::Ollama) {
            return $this->testOllamaConnection($provider);
        }

        $result = $this->runStructuredFor(
            $provider,
            'Reply with the number 1.',
            fn (JsonSchema $schema) => ['ok' => $schema->integer()->required()],
            'Return 1.',
        );

        return $result === null ? 'The AI provider did not return a valid response.' : true;
    }

    /**
     * @return true|string true on success, an error message on failure
     */
    protected function testOllamaConnection(AiProviderConfigDto $provider): true|string
    {
        $baseUrl = $provider->baseUrl;

        if (blank($baseUrl)) {
            return 'No Ollama base URL is configured.';
        }

        try {
            $models = OllamaService::new()->listModels($baseUrl);
        } catch (Throwable $e) {
            Log::warning('Ollama reachability check failed.', ['exception' => $e::class]);

            return "Could not reach Ollama at {$baseUrl}.";
        }

        if (blank($provider->model)) {
            return 'Connected to Ollama, but no model is selected.';
        }

        if (! in_array($provider->model, $models, true)) {
            return "Connected to Ollama, but model '{$provider->model}' was not found. Available: ".
                (implode(', ', $models) ?: 'none').'.';
        }

        return true;
    }

    protected function configureProviderCredentials(AiProviderConfigDto $provider): void
    {
        $driver = $provider->type->driver();

        config(["ai.providers.{$driver}.key" => $this->decrypt($provider->apiKey)]);

        if (filled($provider->baseUrl)) {
            config(["ai.providers.{$driver}.url" => $provider->baseUrl]);
        }
    }

    protected function decrypt(?string $value): ?string
    {
        if (blank($value)) {
            return null;
        }

        try {
            return Crypt::decryptString($value);
        } catch (Throwable) {
            Log::warning('AI provider API key could not be decrypted; check APP_KEY and the stored credentials.');

            return null;
        }
    }
}
```
(This removes the now-unused `resolveModel`/`floatOrNull`/`intOrNull` helpers — the DTO already provides typed values.)

- [ ] **Step 6: Rewrite AiServiceTest to the new shape**

In `tests/Feature/Services/AiServiceTest.php`, replace the `setAiSettings` usages with a helper that builds a provider list. Add this private helper to the class:
```php
/**
 * @param  array<string, mixed>  $overrides
 */
private function setActiveProvider(array $overrides = []): void
{
    $provider = array_merge([
        'id' => 'p1',
        'name' => 'Test',
        'type' => 'anthropic',
        'model' => 'claude-haiku-4-5-20251001',
        'api_key' => \Illuminate\Support\Facades\Crypt::encryptString('test-key'),
        'timeout_seconds' => 30,
        'max_tokens' => 2000,
        'temperature' => 0.1,
    ], $overrides);

    $this->setAiSettings([
        'enabled' => true,
        'default_provider_id' => $provider['id'],
        'providers' => [$provider],
    ]);
}
```
Then update the tests:
- `test_structured_returns_null_when_ai_is_disabled`: `$this->setAiSettings(['enabled' => false, 'providers' => []]);` → assert null.
- `test_structured_returns_array_when_enabled_and_agent_is_faked`: `$this->setActiveProvider();` then `ConfiguredStructuredAgent::fake([['price' => 19.99, 'currency' => 'USD']])` (or your existing structured payload) → assert the array.
- `test_structured_returns_null_and_logs_when_sdk_throws`: `$this->setActiveProvider();` + throwing fake → null.
- Add `test_structured_returns_null_when_default_points_nowhere`:
  ```php
  $this->setAiSettings(['enabled' => true, 'default_provider_id' => 'gone', 'providers' => [
      ['id' => 'p1', 'name' => 'A', 'type' => 'anthropic'],
  ]]);
  expect(AiService::new()->structured('x', fn ($s) => ['ok' => $s->integer()->required()], 'p'))->toBeNull();
  ```
- testConnection tests: keep `disabled → string`; `cloud faked → true` (use `setActiveProvider()`); `cloud no response → string` (throwing fake); the three Ollama tests now seed an ollama provider:
  ```php
  $this->setActiveProvider(['type' => 'ollama', 'api_key' => null,
      'base_url' => 'http://ai.example:11434', 'model' => 'gemma4:e4b']);
  Http::fake(['*/api/tags' => Http::response(['models' => [['name' => 'gemma4:e4b']]])]);
  expect(AiService::new()->testConnection())->toBeTrue();
  ```
  and the missing-model / unreachable variants as before.
Keep the `Http` import.

- [ ] **Step 7: Run the affected tests, confirm PASS**

Run: `lando artisan test --parallel --filter='IntegrationHelperTest|AiServiceTest|AiExtractionServiceTest'`
Expected: all PASS. (AiExtractionService mocks AiService::isEnabled/structured, so it is unaffected — confirm it stays green.)

- [ ] **Step 8: Format + full static analysis + commit**

```bash
lando ssh -c "vendor/bin/pint --dirty"
lando phpcs   # MUST end with [OK] No errors
git add app/Services/Helpers/IntegrationHelper.php app/Services/AiService.php tests/Unit/Services/IntegrationHelperTest.php tests/Feature/Services/AiServiceTest.php
git commit -m "feat: resolve AI config through active-provider DTO (multi-provider backend)"
```

---

## Task 3: Settings migration (old → new shape)

**Files:**
- Create: `app/Settings/AiProvidersRestructure.php`
- Create: `database/settings/2026_06_05_000000_restructure_ai_providers.php`
- Test: `tests/Unit/Settings/AiProvidersRestructureTest.php`

- [ ] **Step 1: Write the failing test for the pure transform**

Create `tests/Unit/Settings/AiProvidersRestructureTest.php`:
```php
<?php

use App\Settings\AiProvidersRestructure;

it('converts a single ollama provider config into the providers array', function () {
    $result = AiProvidersRestructure::transform([
        'ai' => [
            'enabled' => true,
            'provider' => 'ollama',
            'default_model' => 'ignored-for-ollama',
            'timeout_seconds' => 90,
            'max_tokens' => 1234,
            'temperature' => 0.5,
            'ollama' => ['base_url' => 'http://ai.jeznet:11434', 'model' => 'gemma4:e4b'],
        ],
    ]);

    $ai = $result['ai'];
    expect($ai['enabled'])->toBeTrue()
        ->and($ai['providers'])->toHaveCount(1)
        ->and($ai['default_provider_id'])->toBe($ai['providers'][0]['id'])
        ->and($ai['providers'][0]['type'])->toBe('ollama')
        ->and($ai['providers'][0]['base_url'])->toBe('http://ai.jeznet:11434')
        ->and($ai['providers'][0]['model'])->toBe('gemma4:e4b')
        ->and($ai['providers'][0]['timeout_seconds'])->toBe(90)
        ->and($ai)->not->toHaveKeys(['provider', 'default_model', 'ollama', 'timeout_seconds']);
});

it('preserves an encrypted cloud key and uses default_model for cloud providers', function () {
    $result = AiProvidersRestructure::transform([
        'ai' => [
            'enabled' => true,
            'provider' => 'anthropic',
            'default_model' => 'claude-haiku-4-5-20251001',
            'anthropic' => ['api_key' => 'ENCRYPTED', 'base_url' => null],
        ],
    ]);

    expect($result['ai']['providers'][0]['type'])->toBe('anthropic')
        ->and($result['ai']['providers'][0]['api_key'])->toBe('ENCRYPTED')
        ->and($result['ai']['providers'][0]['model'])->toBe('claude-haiku-4-5-20251001');
});

it('produces an empty provider list when no provider was configured', function () {
    $result = AiProvidersRestructure::transform(['ai' => ['enabled' => false]]);

    expect($result['ai']['providers'])->toBe([])
        ->and($result['ai']['default_provider_id'])->toBeNull();
});

it('is idempotent when providers already exist', function () {
    $already = ['ai' => ['enabled' => true, 'default_provider_id' => 'p1', 'providers' => [
        ['id' => 'p1', 'type' => 'ollama'],
    ]]];

    expect(AiProvidersRestructure::transform($already))->toBe($already);
});

it('leaves non-ai integrated services untouched', function () {
    $result = AiProvidersRestructure::transform([
        'search' => ['enabled' => true, 'url' => 'http://searx'],
        'ai' => ['enabled' => false],
    ]);

    expect($result['search'])->toBe(['enabled' => true, 'url' => 'http://searx']);
});
```

- [ ] **Step 2: Run, confirm FAIL**

Run: `lando artisan test --parallel --filter=AiProvidersRestructure`
Expected: FAIL (class missing).

- [ ] **Step 3: Implement the pure transform**

Create `app/Settings/AiProvidersRestructure.php`:
```php
<?php

namespace App\Settings;

use Illuminate\Support\Str;

class AiProvidersRestructure
{
    /**
     * Convert the legacy single-provider `ai` settings into the multi-provider shape.
     * Idempotent: if `ai.providers` already exists, the input is returned unchanged.
     *
     * @param  array<string, mixed>  $services
     * @return array<string, mixed>
     */
    public static function transform(array $services): array
    {
        $ai = $services['ai'] ?? [];

        if (array_key_exists('providers', $ai)) {
            return $services;
        }

        $providers = [];
        $defaultId = null;

        $type = $ai['provider'] ?? null;

        if (filled($type)) {
            $id = (string) Str::ulid();
            $typeSettings = is_array($ai[$type] ?? null) ? $ai[$type] : [];

            $providers[] = [
                'id' => $id,
                'name' => ucfirst((string) $type),
                'type' => $type,
                'base_url' => $typeSettings['base_url'] ?? null,
                'api_key' => $typeSettings['api_key'] ?? null,
                'model' => $type === 'ollama'
                    ? ($typeSettings['model'] ?? null)
                    : ($ai['default_model'] ?? null),
                'timeout_seconds' => (int) ($ai['timeout_seconds'] ?? 60),
                'max_tokens' => (int) ($ai['max_tokens'] ?? 2000),
                'temperature' => (float) ($ai['temperature'] ?? 0.2),
            ];
            $defaultId = $id;
        }

        $services['ai'] = [
            'enabled' => (bool) ($ai['enabled'] ?? false),
            'default_provider_id' => $defaultId,
            'providers' => $providers,
        ];

        return $services;
    }
}
```

- [ ] **Step 4: Run, confirm PASS**

Run: `lando artisan test --parallel --filter=AiProvidersRestructure`
Expected: PASS (5 tests).

- [ ] **Step 5: Create the settings migration**

Create `database/settings/2026_06_05_000000_restructure_ai_providers.php`:
```php
<?php

use App\Settings\AiProvidersRestructure;
use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->update(
            'app.integrated_services',
            fn (array $services): array => AiProvidersRestructure::transform($services),
        );
    }

    public function down(): void
    {
        // Non-reversible: the multi-provider structure cannot be losslessly
        // collapsed back to a single provider.
    }
};
```

- [ ] **Step 6: Run the settings migration against the dev database**

Run:
```bash
lando artisan settings:migrate
```
Expected: runs without error (the migration is idempotent; if the dev settings were already in the new shape from a prior run, it is a no-op). Then sanity-check:
```bash
lando artisan tinker --execute 'echo json_encode(\App\Services\Helpers\IntegrationHelper::getActiveAiProvider());'
```
Expected: prints the active provider JSON (your migrated Ollama provider) or `null` if none was configured.

- [ ] **Step 7: Format + static analysis + commit**

```bash
lando ssh -c "vendor/bin/pint --dirty"
lando phpcs   # MUST end with [OK] No errors
git add app/Settings/AiProvidersRestructure.php database/settings/2026_06_05_000000_restructure_ai_providers.php tests/Unit/Settings/AiProvidersRestructureTest.php
git commit -m "feat: auto-migrate single AI provider settings to multi-provider shape"
```

---

## Task 4: Filament UI — providers Repeater, default select, per-row test + Ollama refresh

This rebuilds the AI section. The Test/refresh logic lives in two public page methods (testable directly); the Repeater actions are thin delegates.

**Files:**
- Modify: `app/Filament/Pages/AppSettingsPage.php`
- Delete: `app/Filament/Actions/Integrations/TestAiConnectionAction.php`
- Modify: `tests/Feature/Filament/AppSettingsAiEncryptionTest.php`
- Modify: `tests/Feature/Filament/AppSettingsOllamaModelsTest.php`

- [ ] **Step 1: Read the current page**

```bash
lando ssh -c "sed -n '1,75p' app/Filament/Pages/AppSettingsPage.php"
lando ssh -c "sed -n '/function getAiSettings/,/^    }/p' app/Filament/Pages/AppSettingsPage.php"
lando ssh -c "sed -n '/function mutateFormDataBeforeSave/,/^    }/p' app/Filament/Pages/AppSettingsPage.php"
```

- [ ] **Step 2: Update imports + the `$ollamaModels` property**

Ensure these imports are present (add any missing): `use App\Services\AiService;`, `use App\Services\Helpers\IntegrationHelper;`, `use Filament\Forms\Components\Hidden;`, `use Filament\Forms\Components\Repeater;`, `use Illuminate\Support\Str;`. Remove `use App\Filament\Actions\Integrations\TestAiConnectionAction;`.

Change the `$ollamaModels` property to be keyed by provider id:
```php
    /**
     * Ollama model names fetched per provider, keyed by provider id.
     *
     * @var array<string, array<int, string>>
     */
    public array $ollamaModels = [];
```

- [ ] **Step 3: Add the two public action methods**

Add to the class:
```php
    /**
     * Fetch installed Ollama models for a provider row and store them by id.
     */
    public function refreshOllamaModelsFor(string $providerId, ?string $baseUrl): void
    {
        if (blank($baseUrl)) {
            Notification::make()->title('Enter the Ollama base URL first.')->warning()->send();

            return;
        }

        try {
            $this->ollamaModels[$providerId] = OllamaService::new()->listModels($baseUrl);

            Notification::make()
                ->title('Loaded '.count($this->ollamaModels[$providerId]).' Ollama model(s).')
                ->success()
                ->send();
        } catch (\Throwable) {
            Notification::make()->title('Could not reach Ollama')->body("No response from {$baseUrl}.")->danger()->send();
        }
    }

    /**
     * Test the saved provider with the given id (requires the settings to be saved).
     */
    public function testProviderById(string $providerId): void
    {
        $provider = collect(IntegrationHelper::getAiProviders())
            ->first(fn ($p): bool => $p->id === $providerId);

        if ($provider === null) {
            Notification::make()->title('Save your settings before testing this provider.')->warning()->send();

            return;
        }

        $result = AiService::new()->testProviderConfig($provider);

        if ($result === true) {
            Notification::make()->title('Connection succeeded')->success()->send();

            return;
        }

        Notification::make()->title('Connection failed')->body($result)->danger()->send();
    }
```

- [ ] **Step 4: Replace `getAiSettings()`**

Replace the entire `getAiSettings()` method with:
```php
    protected function getAiSettings(): Section
    {
        return self::makeSettingsSection(
            'AI',
            self::INTEGRATED_SERVICES_KEY,
            IntegratedServices::Ai->value,
            [
                Repeater::make('providers')
                    ->label('Providers')
                    ->addActionLabel('Add provider')
                    ->collapsible()
                    ->itemLabel(fn (array $state): ?string => $state['name'] ?? 'Provider')
                    ->extraItemActions([
                        Action::make('testProvider')
                            ->icon('heroicon-m-signal')
                            ->tooltip('Test this provider (save first)')
                            ->action(function (array $arguments, Repeater $component, AppSettingsPage $livewire): void {
                                $state = $component->getItemState($arguments['item']);
                                $livewire->testProviderById($state['id'] ?? '');
                            }),
                    ])
                    ->schema([
                        Hidden::make('id')->default(fn (): string => (string) Str::ulid()),
                        TextInput::make('name')
                            ->required()
                            ->placeholder('e.g. Local Ollama'),
                        Select::make('type')
                            ->options([
                                AiProvider::OpenAI->value => 'OpenAI',
                                AiProvider::Anthropic->value => 'Anthropic',
                                AiProvider::Ollama->value => 'Ollama',
                                AiProvider::Gemini->value => 'Gemini',
                            ])
                            ->required()
                            ->live(),
                        TextInput::make('base_url')
                            ->label('Base URL')
                            ->url()
                            ->live(onBlur: true)
                            ->helperText(fn (Get $get): string => $get('type') === AiProvider::Ollama->value
                                ? 'e.g. http://localhost:11434'
                                : 'Leave empty to use default.')
                            ->required(fn (Get $get): bool => $get('type') === AiProvider::Ollama->value),
                        TextInput::make('api_key')
                            ->label('API key')
                            ->password()
                            ->revealable()
                            ->helperText('Leave blank to keep the current key.')
                            ->visible(fn (Get $get): bool => $get('type') !== AiProvider::Ollama->value),
                        TextInput::make('model')
                            ->key('model_text')
                            ->label('Model')
                            ->placeholder('gpt-4.1-mini')
                            ->visible(fn (Get $get): bool => $get('type') !== AiProvider::Ollama->value),
                        Select::make('model')
                            ->key('model_select')
                            ->label('Model')
                            ->native(false)
                            ->searchable()
                            ->placeholder('Refresh to load models')
                            ->visible(fn (Get $get): bool => $get('type') === AiProvider::Ollama->value)
                            ->options(function (AppSettingsPage $livewire, Get $get): array {
                                $models = $livewire->ollamaModels[$get('id')] ?? [];
                                $current = $get('model');

                                if (filled($current) && ! in_array($current, $models, true)) {
                                    $models[] = $current;
                                }

                                return array_combine($models, $models) ?: [];
                            })
                            ->suffixAction(
                                Action::make('refreshOllamaModels')
                                    ->icon('heroicon-m-arrow-path')
                                    ->tooltip('Refresh models from Ollama')
                                    ->action(fn (AppSettingsPage $livewire, Get $get) => $livewire->refreshOllamaModelsFor($get('id'), $get('base_url'))),
                            ),
                        TextInput::make('timeout_seconds')
                            ->label('Timeout seconds')
                            ->helperText('Local models can take ~1 minute to cold-load.')
                            ->numeric()->minValue(1)->default(60),
                        TextInput::make('max_tokens')
                            ->label('Max tokens')
                            ->numeric()->minValue(1)->default(2000),
                        TextInput::make('temperature')
                            ->label('Temperature')
                            ->numeric()->minValue(0)->maxValue(2)->default(0.2),
                    ])
                    ->columns(2),
                Select::make('default_provider_id')
                    ->label('Default provider')
                    ->live()
                    ->options(fn (Get $get): array => collect($get('providers') ?? [])
                        ->filter(fn ($p): bool => filled($p['id'] ?? null))
                        ->mapWithKeys(fn ($p): array => [$p['id'] => filled($p['name'] ?? null) ? $p['name'] : 'Provider'])
                        ->all()),
            ],
            __('Configure one or more AI providers and choose which is used by default.')
        );
    }
```

- [ ] **Step 5: Replace `mutateFormDataBeforeSave()` to encrypt across the providers array**

Replace the method body with:
```php
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $existingProviders = collect(
            data_get(AppSettings::new()->toArray(), 'integrated_services.ai.providers', [])
        );

        $providers = data_get($data, 'integrated_services.ai.providers', []);

        foreach ($providers as $index => $provider) {
            // Ollama has no API key.
            if (($provider['type'] ?? null) === AiProvider::Ollama->value) {
                continue;
            }

            $keyPath = "integrated_services.ai.providers.{$index}.api_key";
            $submitted = data_get($data, $keyPath);

            if (filled($submitted)) {
                try {
                    // Already-encrypted value — leave as-is (idempotent).
                    Crypt::decryptString($submitted);
                } catch (DecryptException) {
                    data_set($data, $keyPath, Crypt::encryptString($submitted));
                }

                continue;
            }

            // Blank submission: restore the stored ciphertext for this provider id.
            $storedKey = $existingProviders->firstWhere('id', $provider['id'] ?? null)['api_key'] ?? null;

            if (filled($storedKey)) {
                data_set($data, $keyPath, $storedKey);
            }
        }

        return $data;
    }
```

- [ ] **Step 6: Delete the now-unused TestAiConnectionAction**

```bash
git rm app/Filament/Actions/Integrations/TestAiConnectionAction.php
```
Confirm nothing else references it:
```bash
lando ssh -c "grep -rn TestAiConnectionAction app tests || echo 'no references'"
```
Expected: `no references`.

- [ ] **Step 7: Rewrite the two Filament test files**

Replace `tests/Feature/Filament/AppSettingsAiEncryptionTest.php`'s test bodies with the repeater shape (keep the class/setUp/`actingAs` and the `setAiSettings` helper). Cover: encrypt new key, blank-to-keep by id, second provider not clobbered, base-URL help text present. Example tests:
```php
public function test_a_new_provider_api_key_is_stored_encrypted(): void
{
    Livewire::test(AppSettingsPage::class)
        ->fillForm([
            'integrated_services.ai.enabled' => true,
            'integrated_services.ai.providers' => [
                ['id' => 'p1', 'name' => 'Claude', 'type' => 'anthropic',
                 'model' => 'claude-haiku-4-5-20251001', 'api_key' => 'sk-ant-secret-123',
                 'timeout_seconds' => 60, 'max_tokens' => 2000, 'temperature' => 0.2],
            ],
            'integrated_services.ai.default_provider_id' => 'p1',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    SettingsHelper::$settings = null;
    Cache::flush();
    Once::flush();

    $stored = data_get(IntegrationHelper::getAiSettings(), 'providers.0.api_key');
    $this->assertNotSame('sk-ant-secret-123', $stored);
    $this->assertSame('sk-ant-secret-123', Crypt::decryptString($stored));
}

public function test_blank_api_key_preserves_the_stored_key_by_id(): void
{
    $this->setAiSettings([
        'enabled' => true,
        'default_provider_id' => 'p1',
        'providers' => [
            ['id' => 'p1', 'name' => 'Claude', 'type' => 'anthropic',
             'model' => 'm', 'api_key' => Crypt::encryptString('keep-me'),
             'timeout_seconds' => 60, 'max_tokens' => 2000, 'temperature' => 0.2],
        ],
    ]);

    Livewire::test(AppSettingsPage::class)
        ->fillForm([
            'integrated_services.ai.enabled' => true,
            'integrated_services.ai.providers' => [
                ['id' => 'p1', 'name' => 'Claude', 'type' => 'anthropic', 'model' => 'm',
                 'api_key' => '', 'timeout_seconds' => 60, 'max_tokens' => 2000, 'temperature' => 0.2],
            ],
            'integrated_services.ai.default_provider_id' => 'p1',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    SettingsHelper::$settings = null;
    Cache::flush();
    Once::flush();

    $stored = data_get(IntegrationHelper::getAiSettings(), 'providers.0.api_key');
    $this->assertSame('keep-me', Crypt::decryptString($stored));
}

public function test_base_url_help_text_is_present_for_cloud_providers(): void
{
    // A cloud-type row must be rendered for the conditional helper text to appear.
    Livewire::test(AppSettingsPage::class)
        ->fillForm([
            'integrated_services.ai.enabled' => true,
            'integrated_services.ai.providers' => [
                ['id' => 'p1', 'name' => 'Claude', 'type' => 'anthropic',
                 'model' => 'm', 'timeout_seconds' => 60, 'max_tokens' => 2000, 'temperature' => 0.2],
            ],
        ])
        ->assertSee('Leave empty to use default.');
}
```
> If `assertSee` does not find the text (Filament may render helper text lazily), assert against the form HTML another way — e.g. `assertSeeHtml` — but keep the assertion that a cloud row's base-URL field shows "Leave empty to use default."

Replace `tests/Feature/Filament/AppSettingsOllamaModelsTest.php` to drive the new public methods directly (keep class/setUp/`actingAs`):
```php
public function test_refresh_populates_models_for_a_provider_id(): void
{
    Http::fake(['*/api/tags' => Http::response(['models' => [
        ['name' => 'gemma4:e4b'], ['name' => 'qwen2.5-coder:7b'],
    ]])]);

    Livewire::test(AppSettingsPage::class)
        ->call('refreshOllamaModelsFor', 'p1', 'http://ai.example:11434')
        ->assertSet('ollamaModels', ['p1' => ['gemma4:e4b', 'qwen2.5-coder:7b']])
        ->assertNotified();
}

public function test_refresh_warns_when_base_url_blank(): void
{
    Livewire::test(AppSettingsPage::class)
        ->call('refreshOllamaModelsFor', 'p1', '')
        ->assertSet('ollamaModels', [])
        ->assertNotified();
}

public function test_refresh_handles_unreachable_ollama(): void
{
    Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('refused'));

    Livewire::test(AppSettingsPage::class)
        ->call('refreshOllamaModelsFor', 'p1', 'http://unreachable:11434')
        ->assertSet('ollamaModels', [])
        ->assertNotified();
}

public function test_test_provider_warns_when_not_saved(): void
{
    Livewire::test(AppSettingsPage::class)
        ->call('testProviderById', 'unknown')
        ->assertNotified();
}

public function test_test_provider_runs_against_a_saved_ollama_provider(): void
{
    $this->setAiSettings([
        'enabled' => true,
        'default_provider_id' => 'p1',
        'providers' => [['id' => 'p1', 'name' => 'Local', 'type' => 'ollama',
            'base_url' => 'http://ai.example:11434', 'model' => 'gemma4:e4b']],
    ]);
    Http::fake(['*/api/tags' => Http::response(['models' => [['name' => 'gemma4:e4b']]])]);

    Livewire::test(AppSettingsPage::class)
        ->call('testProviderById', 'p1')
        ->assertNotified();
}
```
Add a `setAiSettings()` helper to this file (mirroring the other test) and the imports (`Http`, `SettingsHelper`, etc.) as needed.

- [ ] **Step 8: Run the Filament tests, confirm PASS**

Run: `lando artisan test --parallel --filter='AppSettingsAiEncryptionTest|AppSettingsOllamaModelsTest'`
Expected: PASS. Iterate on the form/test details (especially the `assertSee` help-text test and repeater fillForm shape) until green. Do NOT weaken the encryption assertions.

- [ ] **Step 9: Format + full static analysis + commit**

```bash
lando ssh -c "vendor/bin/pint --dirty"
lando phpcs   # MUST end with [OK] No errors
git add app/Filament/Pages/AppSettingsPage.php tests/Feature/Filament/AppSettingsAiEncryptionTest.php tests/Feature/Filament/AppSettingsOllamaModelsTest.php
git commit -m "feat: multi-provider AI settings UI with per-row test and Ollama refresh"
```

---

## Task 5: Full verification sweep

**Files:** none (verification only)

- [ ] **Step 1: Run the entire suite**

Run: `lando artisan test --parallel`
Expected: all green (1 pre-existing skip allowed). Fix any regression before proceeding.

- [ ] **Step 2: Coding standards + static analysis**

Run: `lando phpcs-fix && lando phpcs`
Expected: `[OK] No errors`.

- [ ] **Step 3: Confirm migration idempotency + active provider resolves**

```bash
lando artisan settings:migrate
lando artisan tinker --execute 'echo json_encode(\App\Services\Helpers\IntegrationHelper::getActiveAiProvider());'
```
Expected: no error; prints the active provider (or null).

- [ ] **Step 4: Restart so the running app picks up the new code, then smoke-test the UI**

```bash
lando restart
```
Then load `http://price-buddy.lndo.site/admin` Settings → AI (Playwright MCP if available, login test@test.com / password): add a provider, pick a type, confirm the base-URL help text, the Ollama model dropdown + refresh, the per-row Test button, and the Default provider select. Screenshot for evidence. If Playwright is unavailable, note it and rely on the test suite.

- [ ] **Step 5: Review the branch**

```bash
git log --oneline main..HEAD
```
Expected: the five feature commits from Tasks 1–4 on top of the prior AI work.

---

## Self-Review Notes (for the implementer)

- **Suite stays green per commit:** Task 2 changes IntegrationHelper + AiService + both their tests together (the shape switch is atomic). Task 4 rewrites the form + both Filament tests together. Don't split those or the suite goes red mid-task.
- **Encryption:** the api_key field has no `formatStateUsing`, so on load it carries the stored (masked) ciphertext; the idempotent decrypt-probe guard prevents re-encryption, and an explicit blank restores the stored key matched by provider `id`. Don't add `formatStateUsing`.
- **Two `model` fields share the `model` statePath** with distinct `->key('model_text')` / `->key('model_select')` and complementary `->visible()` by type — only one is active per row.
- **Testable seams:** the Repeater's refresh/test actions delegate to `refreshOllamaModelsFor()` / `testProviderById()`; tests call those methods via `Livewire::test()->call(...)` rather than wrestling repeater item uuids.
- **Out of scope:** per-product provider override (future), scrape-pipeline wiring.
```
