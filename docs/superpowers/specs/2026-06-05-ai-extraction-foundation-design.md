# AI foundation + extraction — design

> PriceGhost parity, Task 1, slice 1. Date: 2026-06-05.
> Branch: `feature/priceghost-parity-ai`.

## Goal

Stand up the AI foundation for PriceBuddy using the [Laravel AI SDK](https://laravel.com/docs/13.x/ai-sdk.md)
(`laravel/ai`), driven entirely by the existing DB-backed AI settings. Deliver one
working AI operation — **extraction fallback** — behind a clean service boundary, so
later slices (verification, arbitration, stock-check, voting/pipeline wiring) can be
added without reshaping the foundation.

## Scope

In scope:

- Install and configure `laravel/ai`.
- `AiService` — the single abstraction bridging AI settings ↔ the SDK / prompting.
- `AiExtractionService` — the extraction-fallback operation only.
- `AiExtractionResultDto`.
- Add the `Gemini` provider (enum + settings UI).
- Encrypt provider API keys at rest.
- A "Test connection" settings action.
- Tests for all of the above.

Out of scope (later slices):

- Verification, arbitration, and stock-status AI operations.
- Multi-candidate price voting and the price-selection modal (Task 2).
- Wiring AI into the scrape pipeline (`Url::updatePrice()` / `ScrapeUrl`).
- Per-product `ai_extraction_disabled` / `ai_verification_disabled` overrides.

## Background / current state

- AI scaffolding already exists on this branch (cherry-pick of `e821705`):
  `App\Enums\AiProvider` (OpenAI/Anthropic/Ollama), `App\Enums\IntegratedServices::Ai`,
  `IntegrationHelper::getAiSettings()/isAiEnabled()/getAiProvider()`, and the AI
  settings section in `App\Filament\Pages\AppSettingsPage`. No code calls an LLM.
- Settings persist via **Spatie Laravel Settings** (`App\Settings\AppSettings`), with
  `integrated_services` as a nested array property. The AI sub-array is shaped:
  `enabled, provider, default_model, timeout_seconds, max_tokens, temperature,
  {openai|anthropic|ollama|gemini}.{base_url, api_key|model}`.
- `laravel/ai` v0.7.2 requires `illuminate/contracts: ^12.0|^13.0` and PHP `^8.3`.
  Project is Laravel 12.56 + PHP 8.4 → installs cleanly, **no framework upgrade**.
- Conventions to follow: DTOs are plain promoted-constructor classes in `App\Dto`;
  services live in `App\Services` with a `static new()` factory using `resolve()`, and
  guard external integrations on `IntegrationHelper::isXEnabled()` (see `SearchService`).

## Architecture

Chosen approach: **anonymous agents, with `AiService` as the sole SDK-touching bridge.**
`AiExtractionService` supplies instructions + JSON schema + prepared HTML and never sees
the SDK. This is the lightest fit for a single-operation slice and puts exactly one seam
over the SDK. (Alternative considered: a dedicated SDK `Agent` class per operation —
rejected for this slice as heavier and still requiring `AiService` for the config bridge.)

### Components

**`App\Services\Ai\ConfiguredStructuredAgent`** — a tiny named subclass of the SDK's
`Laravel\Ai\StructuredAnonymousAgent`. It adds `temperature()`, `maxTokens()`, and
`topP()` methods (constructor-injected from settings). The SDK's
`TextGenerationOptions::forAgent()` resolves these via `method_exists` before falling back
to attributes, so this is how anonymous-style agents get generation options. Being a named
class also gives a clean test seam (`ConfiguredStructuredAgent::fake([...])`).

**`App\Services\AiService`** — the orchestration bridge; with `ConfiguredStructuredAgent`
these are the only two classes that import from `Laravel\Ai`.

```php
AiService::new(): self
public function isEnabled(): bool                       // delegates IntegrationHelper::isAiEnabled()
public function structured(string $instructions, Closure $schema, string $prompt): ?array
public function testConnection(): true|string           // true on success, error message string on failure
```

Internal flow of `structured()`:

1. If `! isEnabled()` → return `null`.
2. Resolve from settings: `AiProvider` → SDK `Lab` enum (`AiProvider::toLab()`), model
   (`default_model`, or provider-specific `ollama.model`), timeout (`timeout_seconds`).
3. Inject provider credentials into the SDK **runtime** config (not the published file,
   to avoid config-cache issues):
   `config(['ai.providers.{driver}.key' => $decryptedKey, 'ai.providers.{driver}.url' => $baseUrl ?: null])`.
4. Build `new ConfiguredStructuredAgent($instructions, $schema, $temperature, $maxTokens)`
   and call `->prompt($prompt, provider: $lab, model: $model, timeout: $timeout)`.
5. Return `$response->toArray()` (the structured array), or `null` on any SDK exception (logged).

**`App\Services\AiExtractionService`** — depends on `AiService`.

```php
public function extract(string $html, ?Collection $schemaOrg = null): ?AiExtractionResultDto
```

1. Guard on `AiService::isEnabled()` → `null` when off.
2. `prepareHtml($html, $schemaOrg)` — port of PriceGhost `prepareHtmlForAI()`: hoist
   JSON-LD blocks + matched price elements to the top, strip `script/style/meta`,
   truncate to **25,000 chars**. Private, well-tested method.
3. Pass PriceGhost's verbatim `EXTRACTION_PROMPT` (from `ai-extractor.ts`) as instructions
   and a JSON schema for `{name, price, currency, imageUrl, stockStatus, confidence}` to
   `AiService::structured()`.
4. Map the result → `AiExtractionResultDto`: parse price via
   `CurrencyHelper::toFloat()`, map availability string → `App\Enums\StockStatus`.
5. Return the DTO, or `null` on empty/failed result.

**`App\Dto\AiExtractionResultDto`** — plain promoted-constructor class:

```php
public function __construct(
    public ?string $title = null,
    public ?float $price = null,
    public ?string $currency = null,
    public ?string $image = null,
    public ?StockStatus $stockStatus = null,
    public float $confidence = 0.0,
) {}
```

**`App\Filament\Actions\Integrations\TestAiConnectionAction`** — mirrors the existing
`TestGotifyAction`; calls `AiService::testConnection()` and notifies success/failure.

### Provider mapping

Add to `App\Enums\AiProvider`:

- `Gemini` case.
- `toLab(): \Laravel\Ai\Enums\Lab` — `OpenAI→Lab::OpenAI`, `Anthropic→Lab::Anthropic`,
  `Ollama→Lab::Ollama`, `Gemini→Lab::Gemini`.
- `driver(): string` — the config key (`'openai'|'anthropic'|'ollama'|'gemini'`).

### Encryption

API-key fields (`openai`, `anthropic`, `gemini`) in `AppSettingsPage` become:

- `->password()->revealable()`.
- Not loaded back into the field on edit (no `formatStateUsing`) — field renders blank.
- `->dehydrated(fn ($state, Get $get) => filled($state) && $get('provider') === <thisProvider>)`
  — persist **only when the user enters a new key** (prevents double-encryption on re-save).
- `->dehydrateStateUsing(fn (string $state) => Crypt::encryptString($state))`.
- Helper text: "Leave blank to keep the current key."

`AiService` decrypts via `Crypt::decryptString()` inside a try/catch that falls back to the
raw value (defensive against any legacy plaintext). No data migration — feature never live.

### SDK install

- `composer require laravel/ai`.
- Publish **config only** (`config/ai.php`); skip the `agent_conversations` migrations —
  conversation memory (`RemembersConversations`) is unused.

## Error handling

| Condition | Behaviour |
|---|---|
| AI disabled in settings | `structured()`/`extract()` return `null` |
| SDK exception (bad key, timeout, rate limit) | log + return `null` |
| Empty / low-confidence result | return `null` |

Callers treat `null` as "no AI result", so nothing breaks when AI is off or fails.

## Testing

- `tests/Unit/Enums/AiProviderTest.php` — add `Gemini` case + `toLab()` mapping.
- `AiServiceTest` — disabled → `null`; provider/model/timeout resolved from settings;
  SDK boundary faked (SDK's own fake if it supports anonymous agents, else `Http::fake`
  the provider endpoint).
- `AiExtractionServiceTest` — bind a mock `AiService` in the container; assert SDK output
  → DTO mapping, availability → `StockStatus`, price parsing, disabled → `null`,
  low-confidence → `null`. Plus `prepareHtml()` behaviour (hoisting, stripping, 25k cap).
- Encryption round-trip — saving a key persists ciphertext; `AiService` reads it decrypted.

## Resolved SDK details (verified against `laravel/ai` 0.x)

1. **Temperature / max_tokens.** `prompt()` only accepts `provider`/`model`/`timeout`.
   Generation options come from the agent via `TextGenerationOptions::forAgent()`, which
   resolves `temperature()`/`maxTokens()`/`topP()`/`maxSteps()` **methods** (then falls back
   to attributes). Solution: `ConfiguredStructuredAgent` defines those methods, fed from
   settings. Returning `null` from a method yields the provider default.
2. **Faking.** Faking is per-agent-class via the `Promptable` trait: `Agent::fake([...])`,
   `Agent::assertPrompted(...)`. For structured agents, `fake()` with no args auto-generates
   schema-shaped data. Because `ConfiguredStructuredAgent` is a named class,
   `ConfiguredStructuredAgent::fake([...])` works in `AiService` tests.
   `AiExtractionService` tests mock `AiService` (our own boundary) regardless.
3. **Response → array.** `StructuredAgentResponse::toArray()` returns the structured array.
4. **Config publish.** `php artisan vendor:publish --tag=ai-config` publishes `config/ai.php`
   only (migrations are a separate, untagged `publishesMigrations` call), so the
   `agent_conversations` tables are not created. The provider key/url are overridden at
   runtime via `config([...])`, so editing the published file is optional.

## File map

New:

- `app/Services/Ai/ConfiguredStructuredAgent.php`
- `app/Services/AiService.php`
- `app/Services/AiExtractionService.php`
- `app/Dto/AiExtractionResultDto.php`
- `app/Filament/Actions/Integrations/TestAiConnectionAction.php`
- `config/ai.php` (published)
- `tests/Unit/Services/AiServiceTest.php` (or Feature, per fake strategy)
- `tests/Feature/Services/AiExtractionServiceTest.php`

Modified:

- `composer.json` / `composer.lock` (add `laravel/ai`)
- `app/Enums/AiProvider.php` (+`Gemini`, `toLab()`, `driver()`)
- `app/Filament/Pages/AppSettingsPage.php` (Gemini fields, encryption pattern, Test action)
- `tests/Unit/Enums/AiProviderTest.php`
