# AI provider error diagnostics Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Distinguish an AI provider/transport error from a genuinely-empty AI result — in the notification and the logs — and log a redacted (key-safe) exception message for diagnosis.

**Architecture:** A new `AiProviderException` is thrown by `AiService::runStructuredFor()` on a caught provider error (after logging a redacted message); empty responses still return `null`. The exception propagates through `structured()`/`extract()` (no change there); callers (`compareWithAi`, `AiScrapeEnhancer`, `testProviderConfig`) catch it to differentiate behaviour. A small `SecretRedactor` strips the API key from logged messages.

**Tech Stack:** Laravel 12, Filament 3, Pest/PHPUnit, Lando (run all artisan/test via `lando`).

**Spec:** `docs/superpowers/specs/2026-06-06-ai-provider-error-diagnostics-design.md`

---

## File structure

- `app/Exceptions/AiProviderException.php` — new typed exception.
- `app/Services/Ai/SecretRedactor.php` — new pure redaction helper.
- `app/Services/AiService.php` — `runStructuredFor()` logs redacted + throws; `testProviderConfig()` catches.
- `app/Filament/Resources/StoreResource/Pages/EditStore.php` — `compareWithAi()` catches → differentiated notifications.
- `app/Services/AiScrapeEnhancer.php` — `enhance()` catches → non-disruptive.
- Tests: `tests/Unit/Services/Ai/SecretRedactorTest.php` (new), `tests/Feature/Services/AiServiceTest.php`, `tests/Feature/Filament/StoreTestModalTest.php`, `tests/Feature/Services/AiScrapeEnhancerTest.php`.

`structured()` and `AiExtractionService::extract()` need **no code change** — they don't catch, so the exception propagates naturally.

---

## Task 1: Exception + SecretRedactor

**Files:**
- Create: `app/Exceptions/AiProviderException.php`
- Create: `app/Services/Ai/SecretRedactor.php`
- Test: `tests/Unit/Services/Ai/SecretRedactorTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Services/Ai/SecretRedactorTest.php`:
```php
<?php

namespace Tests\Unit\Services\Ai;

use App\Services\Ai\SecretRedactor;
use Tests\TestCase;

class SecretRedactorTest extends TestCase
{
    public function test_redacts_the_supplied_secret(): void
    {
        $out = SecretRedactor::redact('failed with key my-secret-123 in request', 'my-secret-123');

        $this->assertStringNotContainsString('my-secret-123', $out);
        $this->assertStringContainsString('[redacted]', $out);
    }

    public function test_redacts_bearer_tokens_even_without_a_supplied_secret(): void
    {
        $out = SecretRedactor::redact('Authorization: Bearer abc.def-123', null);

        $this->assertStringNotContainsString('abc.def-123', $out);
        $this->assertStringContainsString('Bearer [redacted]', $out);
    }

    public function test_redacts_sk_style_keys(): void
    {
        $out = SecretRedactor::redact('used sk-ABCDEFGH12345678 here', null);

        $this->assertStringNotContainsString('sk-ABCDEFGH12345678', $out);
        $this->assertStringContainsString('[redacted]', $out);
    }

    public function test_leaves_unrelated_text_intact(): void
    {
        $out = SecretRedactor::redact('cURL error 28: Connection timed out', null);

        $this->assertSame('cURL error 28: Connection timed out', $out);
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `lando artisan test --compact --filter=SecretRedactorTest`
Expected: FAIL — `App\Services\Ai\SecretRedactor` does not exist.

- [ ] **Step 3: Create the exception**

Create `app/Exceptions/AiProviderException.php`:
```php
<?php

namespace App\Exceptions;

use RuntimeException;

class AiProviderException extends RuntimeException {}
```

- [ ] **Step 4: Create the redactor**

Create `app/Services/Ai/SecretRedactor.php`:
```php
<?php

namespace App\Services\Ai;

class SecretRedactor
{
    /**
     * Remove the configured secret and common credential patterns from a message
     * so it is safe to log.
     */
    public static function redact(string $message, ?string $secret): string
    {
        if (filled($secret)) {
            $message = str_replace($secret, '[redacted]', $message);
        }

        $message = preg_replace('/Bearer\s+[A-Za-z0-9._\-]+/i', 'Bearer [redacted]', $message) ?? $message;
        $message = preg_replace('/\bsk-[A-Za-z0-9._\-]{8,}/', '[redacted]', $message) ?? $message;

        return $message;
    }
}
```

- [ ] **Step 5: Run to verify it passes**

Run: `lando artisan test --compact --filter=SecretRedactorTest`
Expected: PASS (4 tests).

- [ ] **Step 6: Pint + commit**
```bash
vendor/bin/pint --dirty
git add app/Exceptions/AiProviderException.php app/Services/Ai/SecretRedactor.php tests/Unit/Services/Ai/SecretRedactorTest.php
git commit -m "feat: add AiProviderException and SecretRedactor

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 2: `AiService` throws + logs redacted; test-connection surfaces the error

**Files:**
- Modify: `app/Services/AiService.php` (`runStructuredFor`, `testProviderConfig`)
- Test: `tests/Feature/Services/AiServiceTest.php`

- [ ] **Step 1: Update the SDK-throws test (now expects a thrown, redacted-logged exception)**

In `tests/Feature/Services/AiServiceTest.php`, add imports (alongside existing `use` lines):
```php
use App\Exceptions\AiProviderException;
use Illuminate\Support\Facades\Log;
```
Replace the existing `test_structured_returns_null_and_logs_when_sdk_throws` method with:
```php
    public function test_structured_throws_and_logs_redacted_when_sdk_throws(): void
    {
        // Provider api_key decrypts to 'test-key' (see setActiveProvider()).
        $this->setActiveProvider();
        Log::spy();

        ConfiguredStructuredAgent::fake(function () {
            throw new \RuntimeException('Unauthorized: Bearer test-key was rejected');
        });

        try {
            AiService::new()->structured(
                'Extract.',
                fn (JsonSchema $schema) => ['price' => $schema->number()->required()],
                '<html>...</html>',
            );
            $this->fail('Expected AiProviderException to be thrown.');
        } catch (AiProviderException $e) {
            $this->assertStringContainsString('RuntimeException', $e->getMessage());
        }

        Log::shouldHaveReceived('error')->withArgs(function (string $message, array $context): bool {
            return $message === 'AI structured prompt failed.'
                && ! str_contains($context['message'], 'test-key')
                && str_contains($context['message'], '[redacted]');
        });
    }
```
(Leave the other tests — disabled→null, empty/array results — unchanged.)

- [ ] **Step 2: Run to verify it fails**

Run: `lando artisan test --compact --filter=test_structured_throws_and_logs_redacted_when_sdk_throws`
Expected: FAIL — `structured()` currently returns null (no exception thrown), and the log lacks a `message` key.

- [ ] **Step 3: Update `runStructuredFor()`**

In `app/Services/AiService.php`, add imports:
```php
use App\Exceptions\AiProviderException;
use App\Services\Ai\SecretRedactor;
```
Replace the `catch (Throwable $e)` block inside `runStructuredFor()` with:
```php
        } catch (Throwable $e) {
            Log::error('AI structured prompt failed.', [
                'provider' => $provider->type->driver(),
                'model' => $provider->model,
                'exception' => $e::class,
                'message' => SecretRedactor::redact($e->getMessage(), $this->decrypt($provider->apiKey)),
            ]);

            throw new AiProviderException("AI provider request failed ({$e::class}).", previous: $e);
        }
```
Leave the `try { ... return $response instanceof StructuredAgentResponse ? $response->toArray() : null; }` part unchanged.

- [ ] **Step 4: Update `testProviderConfig()` to catch the exception**

In `app/Services/AiService.php`, change the cloud-provider branch of `testProviderConfig()` so the `runStructuredFor` call is wrapped:
```php
        try {
            $result = $this->runStructuredFor(
                $provider,
                'Reply with the number 1.',
                fn (JsonSchema $schema) => ['ok' => $schema->integer()->required()],
                'Return 1.',
            );
        } catch (AiProviderException $e) {
            return $e->getMessage();
        }

        return $result === null ? 'The AI provider did not return a valid response.' : true;
```
(The Ollama branch above it is unchanged.)

- [ ] **Step 5: Run to verify it passes**

Run: `lando artisan test --compact --filter=AiServiceTest`
Expected: PASS (all AiService tests, including the updated throw test).

- [ ] **Step 6: Pint + commit**
```bash
vendor/bin/pint --dirty
git add app/Services/AiService.php tests/Feature/Services/AiServiceTest.php
git commit -m "feat: AiService throws AiProviderException with redacted log on provider failure

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 3: Callers differentiate provider error vs no data

**Files:**
- Modify: `app/Filament/Resources/StoreResource/Pages/EditStore.php` (`compareWithAi`)
- Modify: `app/Services/AiScrapeEnhancer.php` (`enhance`)
- Test: `tests/Feature/Filament/StoreTestModalTest.php`
- Test: `tests/Feature/Services/AiScrapeEnhancerTest.php`

- [ ] **Step 1: Write the failing tests**

In `tests/Feature/Filament/StoreTestModalTest.php`, add import:
```php
use App\Exceptions\AiProviderException;
```
**Update** the existing `test_compare_with_ai_warns_when_extract_returns_null` so its notification assertion expects the new title — change `->assertNotified('AI could not extract any data')` to:
```php
            ->assertNotified('AI found no data in the page');
```
**Add** a provider-error test:
```php
    public function test_compare_with_ai_shows_provider_error_notification(): void
    {
        $this->configureAiProvider();
        $this->mockScrape('19.99', 'Widget');
        $store = Store::factory()->create([
            'settings' => ['scraper_service' => 'http'],
            'domains' => [['domain' => 'example.com']],
        ]);

        $this->mock(AiExtractionService::class, fn ($m) => $m->shouldReceive('extract')
            ->once()->andThrow(new AiProviderException('AI provider request failed (X).')));

        $component = Livewire::test(EditStore::class, ['record' => $store->getKey()])
            ->call('runScrape', 'https://example.com/p')
            ->call('compareWithAi')
            ->assertNotified('AI provider error');

        $this->assertNull($component->get('testAiResult'));
    }
```

In `tests/Feature/Services/AiScrapeEnhancerTest.php`, add import:
```php
use App\Exceptions\AiProviderException;
```
Add a test (reusing the file's existing `configureProviders()` and `url()` helpers):
```php
    public function test_leaves_result_unchanged_on_provider_error(): void
    {
        $this->configureProviders();
        $this->mock(AiExtractionService::class, fn ($m) => $m->shouldReceive('extract')
            ->once()->andThrow(new AiProviderException('AI provider request failed (X).')));

        $result = AiScrapeEnhancer::new()->enhance($this->url(), ['price' => null, 'body' => '<html>9.99</html>']);

        $this->assertNull($result['price']);
    }
```
(`AiExtractionService` is already imported in this file. If `Log` facade expectations elsewhere in the file are strict, this test does not assert logs, so it should not conflict; the enhancer's own `Log::channel('db')` call is exercised but unasserted here.)

- [ ] **Step 2: Run to verify they fail**

Run: `lando artisan test --compact --filter="test_compare_with_ai_shows_provider_error_notification|test_leaves_result_unchanged_on_provider_error|test_compare_with_ai_warns_when_extract_returns_null"`
Expected: FAIL — `compareWithAi`/`enhance` don't catch `AiProviderException` yet (the exception escapes), and the no-data title is still the old string.

- [ ] **Step 3: Update `compareWithAi()`**

In `app/Filament/Resources/StoreResource/Pages/EditStore.php`, add import:
```php
use App\Exceptions\AiProviderException;
```
Replace the part of `compareWithAi()` from the `extract` call through the null check with:
```php
        try {
            $result = AiExtractionService::new()->extract((string) $body, provider: $provider);
        } catch (AiProviderException) {
            Notification::make()
                ->title('AI provider error')
                ->body('Check the AI provider settings and logs.')
                ->danger()
                ->send();

            return;
        }

        if ($result === null) {
            Notification::make()->title('AI found no data in the page')->warning()->send();

            return;
        }

        $this->testAiResult = [
            'title' => $result->title,
            'price' => $result->price,
            'currency' => $result->currency,
            'image' => $result->image,
            'availability' => $result->stockStatus?->getLabel(),
            'confidence' => $result->confidence,
        ];
```
(The earlier guards — `blank($body)` → "No scraped HTML to analyse"; `$provider === null` → "No AI provider configured" — are unchanged. The previous unconditional "AI could not extract any data" notification is replaced by the two branches above.)

- [ ] **Step 4: Update `AiScrapeEnhancer::enhance()`**

In `app/Services/AiScrapeEnhancer.php`, add import:
```php
use App\Exceptions\AiProviderException;
```
Wrap the `extract` call. Replace:
```php
        $result = $this->extraction->extract($html, provider: $provider);
```
with:
```php
        try {
            $result = $this->extraction->extract($html, provider: $provider);
        } catch (AiProviderException) {
            // @phpstan-ignore-next-line - withContext is valid.
            Log::channel('db')->withContext(['url' => $url->url])
                ->warning('AI provider error during scrape enhancement; leaving price unchanged.');

            return $scrapeResult;
        }
```
(`Log` is already imported in this file. Everything after — the confidence check, `data_set`, success logging — is unchanged.)

- [ ] **Step 5: Run to verify they pass**

Run: `lando artisan test --compact --filter="test_compare_with_ai_shows_provider_error_notification|test_leaves_result_unchanged_on_provider_error|test_compare_with_ai_warns_when_extract_returns_null"`
Expected: PASS.

Run the affected suites to confirm no regressions:
Run: `lando artisan test --compact --filter="StoreTestModalTest|AiScrapeEnhancerTest"`
Expected: PASS.

- [ ] **Step 6: Pint + commit**
```bash
vendor/bin/pint --dirty
git add app/Filament/Resources/StoreResource/Pages/EditStore.php app/Services/AiScrapeEnhancer.php tests/Feature/Filament/StoreTestModalTest.php tests/Feature/Services/AiScrapeEnhancerTest.php
git commit -m "feat: differentiate AI provider error from empty result in callers

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 4: Standards + full suite

**Files:** none (verification only)

- [ ] **Step 1: Coding standards**

Run: `lando phpcs-fix && lando phpcs`
Expected: Pint PASS, PHPStan `[OK] No errors`. (Reach the PHPStan `[OK]` — a Pint failure short-circuits before it.)

- [ ] **Step 2: Full suite**

Run: `lando artisan test --parallel`
Expected: all green.

- [ ] **Step 3: Commit any standards fixes**
```bash
git add -A
git commit -m "style: phpcs fixes for AI provider error diagnostics"
```
(Skip if nothing changed.)

---

## Self-review notes

- **Spec §1 (AiProviderException):** Task 1.
- **Spec §2 (SecretRedactor):** Task 1.
- **Spec §3 (runStructuredFor log redacted + throw):** Task 2.
- **Spec §4 (structured/extract propagate):** no code change — verified they don't catch.
- **Spec §5 (compareWithAi differentiate):** Task 3.
- **Spec §6 (enhancer non-disruptive):** Task 3.
- **Spec §7 (testProviderConfig surfaces error):** Task 2.
- **Spec testing section:** SecretRedactor unit (Task 1); AiService throw+redaction (Task 2); compareWithAi provider-error + no-data, enhancer non-disruption (Task 3); phpcs + parallel (Task 4).
- **Type consistency:** `AiProviderException` (RuntimeException), `SecretRedactor::redact(string, ?string): string` — used identically in AiService, the redactor test, and caller catches. Notification titles: provider error = "AI provider error"; no data = "AI found no data in the page" (the latter replaces the old "AI could not extract any data", and the previously-added test asserting the old string is updated in Task 3 Step 1).
- **Ordering:** Task 2 makes `runStructuredFor` throw; the only un-caught callers between Task 2 and Task 3 are `compareWithAi`/`enhancer`, neither exercised with a throwing provider by existing tests (their mocks return values/null), so no test breaks mid-sequence; Task 3 adds the catches.
