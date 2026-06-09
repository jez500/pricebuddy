# Multiple AI providers with selectable default — design

> PriceGhost parity follow-up. Date: 2026-06-05.
> Branch: `feature/priceghost-parity-ai`.

## Goal

Let a user configure **several AI providers** in Settings → AI and pick one as the
**default** (active) provider, instead of the current single-provider configuration.
Also add help text "Leave empty to use default" under cloud providers' base-URL fields.

## Scope

In scope:

- New settings data structure: `providers[]` (each with a stable id, name, type, and its
  own credentials + generation params) plus `default_provider_id`.
- One-time automatic migration of the existing single-provider config into the new shape.
- A resolver (`AiProviderConfigDto` + `IntegrationHelper` methods) so consumers depend on
  the *active provider*, not the storage shape.
- `AiService` consumes the resolver; a per-provider `testProviderConfig()`.
- Filament UI: a `Repeater` of providers with per-row Test + (Ollama) model dropdown/refresh,
  and a "Default provider" select. Base-URL help text for cloud types.
- Per-provider API-key encryption (blank-to-keep, matched by id) and per-provider Ollama
  model state.

Out of scope (future slices):

- Per-product provider override (would reference a provider `id`).
- Wiring AI into the scrape pipeline / voting (separate roadmap task).

## Background / current state

Current `integrated_services.ai` shape:
`{ enabled, provider, default_model, timeout_seconds, max_tokens, temperature,
{openai|anthropic|gemini}:{base_url, api_key}, ollama:{base_url, model} }`.

Consumers (all to be updated):

- `IntegrationHelper::getAiSettings()/isAiEnabled()/getAiProvider()`.
- `AiService::structured()/testConnection()/testOllamaConnection()` — reads provider type,
  per-type subkeys, and the top-level generation params.
- `AppSettingsPage::getAiSettings()` (form) and `mutateFormDataBeforeSave()` (encryption,
  iterates `openai/anthropic/gemini`).
- Tests: `IntegrationHelperTest`, `AppSettingsAiEncryptionTest`, `AppSettingsOllamaModelsTest`,
  `AiServiceTest`.

Settings persist via Spatie Laravel Settings (`AppSettings`, group `app`,
`integrated_services` is an `array` property). Settings migrations live in
`database/settings/` as `SettingsMigration` classes; `$this->migrator->update('app.<key>',
fn ($value) => …)` transforms an existing value in place.

`AppSettingsPage::makeSettingsSection()` already renders the master `ai.enabled` toggle and
hides the rest of the AI group unless enabled — this is retained.

## Data structure

```
integrated_services.ai = {
  enabled: bool,                  // master switch (existing toggle)
  default_provider_id: ?string,   // id of the active provider
  providers: [
    {
      id: string,                 // generated ulid, stable across saves
      name: string,               // user label, e.g. "Local Ollama (gemma)"
      type: 'openai'|'anthropic'|'ollama'|'gemini',
      base_url: ?string,          // optional; empty = provider default
      api_key: ?string,           // encrypted at rest; unused for ollama
      model: ?string,             // per-provider model
      timeout_seconds: int,       // per-provider (default 60)
      max_tokens: int,            // per-provider (default 2000)
      temperature: float,         // per-provider (default 0.2)
    }, …
  ]
}
```

The old top-level `default_model`/`timeout_seconds`/`max_tokens`/`temperature` and the
per-type subkeys are removed — folded into each provider entry.

## Migration (one-time, automatic, idempotent)

A new `database/settings/<ts>_restructure_ai_providers.php` `SettingsMigration`:

`up()` — `$this->migrator->update('app.integrated_services', function (array $svc) { … })`:

1. If `data_get($svc, 'ai.providers')` already exists → return `$svc` unchanged (idempotent).
2. Read the old `ai` keys. If a `provider` type is set, build one provider entry:
   - `id` = a generated ulid (computed in PHP; safe in a migration).
   - `name` = ucfirst(type) (e.g. "Ollama").
   - `type` = old `provider`.
   - `base_url` = old `{type}.base_url`; `api_key` = old `{type}.api_key` (kept encrypted as-is).
   - `model` = old `ollama.model` for ollama, else old `default_model`.
   - `timeout_seconds`/`max_tokens`/`temperature` = old top-level values (fallback 60/2000/0.2).
3. Set `ai.providers` = `[entry]` (or `[]` if no old provider), `ai.default_provider_id` =
   entry id (or null), preserve `ai.enabled`. Remove the obsolete old keys
   (`provider`, `default_model`, `timeout_seconds`, `max_tokens`, `temperature`,
   `openai`, `anthropic`, `ollama`, `gemini`).
4. Return the rewritten `$svc`.

`down()` is a no-op (or best-effort reverse to the first provider) — note it as non-reversible.

## Resolver abstraction

New `App\Dto\AiProviderConfigDto` (plain promoted-constructor class):

```php
public function __construct(
    public string $id,
    public string $name,
    public AiProvider $type,
    public ?string $model = null,
    public ?string $baseUrl = null,
    public ?string $apiKey = null,        // still encrypted; AiService decrypts
    public int $timeoutSeconds = 60,
    public int $maxTokens = 2000,
    public float $temperature = 0.2,
) {}
```

`IntegrationHelper`:

- `getAiProviders(): array<int, AiProviderConfigDto>` — map `ai.providers[]` → DTOs, skipping
  entries with an unknown/blank `type` (via `AiProvider::tryFrom`).
- `getActiveAiProvider(): ?AiProviderConfigDto` — the provider whose `id ===
  data_get(ai, 'default_provider_id')`; null if none/blank.
- `isAiEnabled(): bool` — `data_get(ai, 'enabled', false) && getActiveAiProvider() !== null`.
- Replace `getAiProvider(): ?AiProvider` with `getActiveAiProvider()?->type` at call sites
  (it is only used internally + in tests). Remove the old method.
- `getAiSettings(): array` stays (raw array, used by the form).

## AiService

- `structured()`: `$cfg = IntegrationHelper::getActiveAiProvider(); if ($cfg === null) return null;`
  Inject `config(['ai.providers.'.$cfg->type->driver().'.key' => decrypt($cfg->apiKey),
  'ai.providers.'.$cfg->type->driver().'.url' => $cfg->baseUrl])` (url only when filled).
  Build `ConfiguredStructuredAgent(temperature: $cfg->temperature, maxTokens: $cfg->maxTokens)`
  and `prompt(provider: $cfg->type->toLab(), model: $cfg->model, timeout: $cfg->timeoutSeconds)`.
  The existing `configureProviderCredentials`/`resolveModel`/`decrypt` helpers adapt to take the
  DTO (or are inlined); keep the broad-catch → log structured context → null.
- New `testProviderConfig(AiProviderConfigDto $cfg): true|string`:
  - Ollama type → `OllamaService::listModels($cfg->baseUrl)` reachability + model-presence check
    (the current `testOllamaConnection` logic, parameterised by the DTO).
  - Cloud type → a small structured generation probe using that provider's config (reuse
    `structured()` against the active provider — see note below).
- `testConnection()` keeps working for the active provider by delegating to
  `testProviderConfig(getActiveAiProvider())` (returns the not-configured message when null).

Note: the cloud generation probe needs the provider's config applied. The simplest correct
form is: `testProviderConfig` for a cloud type temporarily makes its DTO the one `structured()`
uses. To avoid global-state juggling, the per-row Test action resolves the **saved** provider
by id and calls a path that configures runtime credentials from that DTO then runs the probe —
implemented as a private `runStructuredFor(AiProviderConfigDto, instructions, schema, prompt)`
that `structured()` also delegates to. This keeps a single code path that takes an explicit DTO.

## Filament UI (`getAiSettings()` rebuilt)

- Master `enabled` toggle: unchanged (provided by `makeSettingsSection`).
- `Repeater::make('providers')`:
  - `Hidden::make('id')->default(fn () => (string) Str::ulid())`.
  - `TextInput::make('name')->required()`.
  - `Select::make('type')->options(<AiProvider cases>)->live()->required()`.
  - `TextInput::make('base_url')->url()` — label adapts to type; **helper text "Leave empty to
    use default"** for cloud types (improvement #1); for Ollama, required + the localhost
    placeholder.
  - `TextInput::make('api_key')->password()->revealable()->helperText('Leave blank to keep the
    current key.')->visible(type !== ollama)` — blank-to-keep.
  - Model field, toggled by `type`:
    - Ollama → `Select::make('model')->searchable()` with a refresh `suffixAction` reading
      *this row's* `base_url` and populating `$ollamaModels[$rowId]`.
    - Cloud → `TextInput::make('model')` with a placeholder.
  - `TextInput::make('timeout_seconds')->default(60)`, `max_tokens`->default(2000),
    `temperature`->default(0.2) — numeric, per provider.
  - `->extraItemActions([Action::make('test') … ])` — per-row Test (save-first; resolves the
    saved provider by id and calls `AiService::testProviderConfig`, notifying the result).
  - `->itemLabel(fn (array $state) => $state['name'] ?? 'Provider')`, `->addActionLabel('Add
    provider')`, `->collapsible()`.
- `Select::make('default_provider_id')->label('Default provider')` — options built live from the
  current providers in form state (`id => name`), `->live()`.

## Encryption (`mutateFormDataBeforeSave`) — generalised to the array

Replace the per-key (openai/anthropic/gemini) loop with iteration over the submitted
`integrated_services.ai.providers[]`:

- For each provider entry, key path `…ai.providers.{index}.api_key`:
  - filled → idempotent-encrypt (decrypt-probe guard, as today).
  - blank → restore the stored ciphertext from the previously-saved provider with the **same
    `id`** (look it up in `AppSettings::new()->toArray()` providers); if no stored match (new
    provider), leave blank.
- Ollama entries have no `api_key`, so they are skipped naturally.

## Ollama model state — keyed by provider id

`public array $ollamaModels = []` becomes `array<string, array<int, string>>` keyed by provider
id. The per-row refresh action sets `$livewire->ollamaModels[$get('id')] = OllamaService::new()
->listModels($get('base_url'))`; the row's model `Select` options read
`$livewire->ollamaModels[$get('id')] ?? []`, always merging the row's current `model` value as a
fallback.

## Error handling

| Condition | Behaviour |
|---|---|
| AI disabled or no valid default provider | `isAiEnabled()` false; `structured()`/`testConnection()` return null / message |
| `default_provider_id` points to a deleted provider | `getActiveAiProvider()` returns null → treated as disabled |
| Per-row Test on an unsaved/new provider | "Save your settings before testing." notification |
| Ollama refresh, base_url blank / unreachable | warning / danger notification; `ollamaModels[id]` unchanged |
| API key decrypt failure | log warning, treat key as absent (existing behaviour) |

## Testing

- `IntegrationHelperTest` — rewrite for the new shape: `getAiProviders()` maps entries;
  `getActiveAiProvider()` selects by `default_provider_id`; `isAiEnabled()` true only when
  enabled AND a valid default exists; deleted-default → null.
- Settings-migration test — seed the OLD blob, run the migration, assert the new shape (one
  provider entry, `default_provider_id` set, encrypted key + ollama model preserved, old keys
  removed); idempotent on a second run.
- `AiServiceTest` — update seeding to the new shape; `structured()` uses the active provider;
  `testProviderConfig`/`testConnection` for Ollama (reachability via `Http::fake`) and cloud
  (via `ConfiguredStructuredAgent::fake`); disabled/no-default → null.
- `AppSettingsPage` — repeater encryption: a new provider's typed key is stored encrypted;
  blank key on re-save preserved by id; a second provider's key isn't clobbered; switching
  `default_provider_id` works. Per-row Ollama refresh populates `ollamaModels[id]`. Base-URL
  help text present for a cloud row.
- `AiExtractionServiceTest` — unaffected (mocks `AiService`); run to confirm.

## File map

New:
- `app/Dto/AiProviderConfigDto.php`
- `database/settings/<ts>_restructure_ai_providers.php`
- tests: settings-migration test, repeater encryption/refresh tests (extend existing files).

Modified:
- `app/Services/Helpers/IntegrationHelper.php`
- `app/Services/AiService.php`
- `app/Filament/Pages/AppSettingsPage.php`
- `tests/Unit/Services/IntegrationHelperTest.php`
- `tests/Feature/Services/AiServiceTest.php`
- `tests/Feature/Filament/AppSettingsAiEncryptionTest.php`
- `tests/Feature/Filament/AppSettingsOllamaModelsTest.php`
