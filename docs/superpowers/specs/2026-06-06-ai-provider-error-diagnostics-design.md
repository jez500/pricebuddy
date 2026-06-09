# AI provider error diagnostics — design

## Goal

When AI extraction fails, distinguish a **provider/transport error** (misconfig,
bad key, timeout, unreachable host) from the AI **genuinely finding no data**, in
both the user-facing notification and the logs — and make the logs verbose enough
to diagnose, without leaking the API key.

## Background — current state

- `AiService::runStructuredFor()` wraps the provider call in `try/catch (Throwable)`.
  The catch logs `Log::error('AI structured prompt failed.', ['provider' => driver, 'exception' => $e::class])`
  — deliberately **without** the exception message (commit `e0a8731` removed it
  because provider errors can embed the API key / auth header) — then returns `null`.
- `structured()` returns `null` when there is no provider, or whatever
  `runStructuredFor()` returns.
- `AiExtractionService::extract()` returns `null` when there is no provider or when
  `structured()` returns a blank result.
- Three callers treat `null` as "no result":
  - `EditStore::compareWithAi()` → notification "AI could not extract any data".
  - `AiScrapeEnhancer::enhance()` → logs debug, returns the scrape result unchanged.
  - `AiService::testProviderConfig()` → returns "The AI provider did not return a valid response."

The problem: a transport error and an empty-but-successful response both collapse
to `null`, so the user sees the same generic message and the logs lack detail.

## Decisions

1. **Verbosity vs. key safety:** log the exception class **and a redacted message**
   (+ provider type + model). Redaction strips the configured API key and common
   token patterns.
2. **Signalling:** introduce a typed `AiProviderException` thrown on a caught
   provider/transport error. A successful-but-empty response still returns `null`.
   Callers catch the exception to differentiate.

## Detailed design

### 1. `App\Exceptions\AiProviderException`

```php
namespace App\Exceptions;

class AiProviderException extends \RuntimeException {}
```

Thrown only for provider/transport failures. Its message is **generic and
key-safe** (e.g. `"AI provider request failed (ConnectException)."`); the original
`Throwable` is attached as `previous`.

### 2. `App\Services\Ai\SecretRedactor`

```php
namespace App\Services\Ai;

class SecretRedactor
{
    public static function redact(string $message, ?string $secret): string
    {
        if (filled($secret)) {
            $message = str_replace($secret, '[redacted]', $message);
        }

        // Scrub common credential patterns regardless of the configured key.
        $message = preg_replace('/Bearer\s+[A-Za-z0-9._\-]+/i', 'Bearer [redacted]', $message) ?? $message;
        $message = preg_replace('/\bsk-[A-Za-z0-9._\-]{8,}/', '[redacted]', $message) ?? $message;

        return $message;
    }
}
```

Pure, static, unit-testable. The replacement token is `[redacted]`.

### 3. `AiService::runStructuredFor()` — log redacted + throw

In the `catch (Throwable $e)` block:

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

The `$response instanceof StructuredAgentResponse ? ... : null` line is unchanged
(a non-structured response is "empty", not an error). `decrypt()` already exists on
the service and returns null safely if the key can't be decrypted.

### 4. `structured()` and `AiExtractionService::extract()` — propagate

Neither catches `AiProviderException`; it propagates to the caller. Both still
return `null` for the "no provider" / "blank result" cases (unchanged).

### 5. `EditStore::compareWithAi()` — differentiate the notification

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

        $this->testAiResult = [ ... ]; // unchanged mapping
```

The existing "No AI provider configured" and "No scraped HTML to analyse" guards
stay as-is. The notification body is intentionally generic (no exception text);
detail lives in the logs. Import `App\Exceptions\AiProviderException`.

### 6. `AiScrapeEnhancer::enhance()` — stay non-disruptive

Wrap the extract call so a provider error never breaks scraping / price updates:

```php
        try {
            $result = $this->extraction->extract($html, provider: $provider);
        } catch (AiProviderException $e) {
            // @phpstan-ignore-next-line - withContext is valid.
            Log::channel('db')->withContext(['url' => $url->url])
                ->warning('AI provider error during scrape enhancement; leaving price unchanged.');

            return $scrapeResult;
        }
```

The rest of `enhance()` (confidence check, `data_set`, success logging) is unchanged.

### 7. `AiService::testProviderConfig()` — surface the real error

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

This improves the "Test connection" action — it now reports the provider failure
instead of the generic "did not return a valid response."

## Testing

- **`SecretRedactor` unit test** (`tests/Unit/Services/Ai/SecretRedactorTest.php`):
  redacts a supplied secret occurrence; redacts a `Bearer <token>`; redacts an
  `sk-…` key; leaves unrelated text intact; handles a `null` secret.
- **`AiService`** (`tests/Feature/Services/AiServiceTest.php`): the existing
  "sdk throws" test changes — `structured()` now **throws `AiProviderException`**
  (was: returned null) and logs a message with the key **absent** (assert the raw
  key string is not in the logged `message`). Keep the empty-response → `null` test.
  (This file already fakes the agent / forces the SDK to throw; reuse that seam.)
- **`compareWithAi`** (`tests/Feature/Filament/StoreTestModalTest.php`): mock
  `AiExtractionService::extract` to throw `AiProviderException` → assert the
  **danger** notification "AI provider error"; mock it to return `null` → assert
  the **warning** "AI found no data in the page".
- **`AiScrapeEnhancer`** (`tests/Feature/Services/AiScrapeEnhancerTest.php`): mock
  `extract` to throw `AiProviderException` → `enhance()` returns the scrape result
  unchanged and does not throw.
- **`testProviderConfig`**: a provider error returns the exception message (verify
  via the existing AiService test seam if practical; otherwise covered by the
  AiService throw test + the catch being trivial).
- `lando phpcs-fix && lando phpcs` to `[OK] No errors`; `lando artisan test --parallel`.

## Out of scope

- Retry/backoff on provider errors.
- Changing the AI SDK, the extraction prompt, or the provider config model.
- Surfacing provider error text in the UI (kept in logs only).
