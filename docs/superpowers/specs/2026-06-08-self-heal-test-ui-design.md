# Self-Healing Test UI (Preview & Apply) — Design

**Date:** 2026-06-08
**Status:** Approved (pending implementation plan)
**Builds on:** the AI self-healing feature (`AiConfigHealer`, `HealingContext`).

## 1. Overview & goal

Add a "Heal with AI" capability to the existing EditStore **Test modal**, alongside the
current "Compare with AI" button. It runs the self-healing agent against the store's
`test_url`, **previews** the proposed `scrape_strategy` (the selector/regex for each field
plus the value it extracts) **without persisting anything**, and lets the user **apply** the
whole proposal into the store form fields. The user then reviews the populated form and
**Saves** like any other edit. This provides a human checkpoint over AI-proposed selectors
— the mitigation for "a validated selector is non-empty but not necessarily correct".

## 2. Non-persisting preview on `AiConfigHealer`

The existing `healStoreForUrl()` persists (creates/updates a store). The UI needs a
preview that runs the agent but saves nothing.

### Shared core extraction
Extract the context-build + fetch-if-blank + agent run from `healStoreForUrl()` into a
private helper so both paths share it:

```
runAgentForUrl(string $url, ?Store $store, ?string $html, AiProviderConfigDto $provider): array{HealingContext, ?array}
```

It builds a `HealingContext` (using the passed store, or a transient `new Store(['settings'=>[]])`),
fetches static HTML when none was supplied (logging + returning a null result on fetch
failure), and runs the existing `attemptAgentRepair`. `healStoreForUrl()` is refactored
onto it with its behaviour unchanged (its lock/cooldown/persist logic stays around the
call; on a null result it still `markAiHealFailed()`s an existing store). The existing
healer tests guard this.

### New public method
```
previewForUrl(string $url, ?Store $store, ?string $html = null): ?array
```
- Resolves the provider via `resolveFeatureProvider(AiFeature::Healing, $store)`; null → null.
- Calls `runAgentForUrl(...)`. On a null agent result → returns null.
- Returns `['fields' => [field => {type,value,prepend,append}], 'extracted' => [field => value], 'usedBrowser' => bool]`.
- **No persistence, no per-store lock, no cooldown, no opt-out check** — those guard the
  automatic background path; this is an explicit, user-initiated action. (The UI itself is
  gated by `isFeatureEnabled(Healing)`.) Note: preview deliberately ignores the per-store
  `ai_self_healing_disabled` opt-out — that flag stops *automatic* repair, whereas an admin
  manually previewing in the Test modal (and Saving afterwards) is an explicit choice.

## 3. EditStore (Livewire) additions

Alongside `runScrape()` / `compareWithAi()`:

- A transient property `public ?array $healPreview = null;` (reset to null in the Test
  action's existing `mountUsing` closure, beside the other `test*` resets).
- `previewSelfHeal(string $url): void` — authorizes, builds the unsaved store via the
  existing `buildUnsavedStore()`, calls
  `AiConfigHealer::new()->previewForUrl($url, $store, data_get($this->testScrapeResult, 'body'))`
  (reusing the test scrape's HTML when present, else the agent fetches). Stores the result
  in `$this->healPreview`, or shows a `Notification` when AI returns nothing / is
  unavailable (same pattern as `compareWithAi`).
- `applySelfHeal(): void` — for each field in `$healPreview['fields']`, writes the slot into
  the form state `$this->data['scrape_strategy'][$field]`; if `$healPreview['usedBrowser']`,
  sets `$this->data['settings']['scraper_service'] = ScraperService::Api->value`; refills the
  form so the fields re-render; clears `$healPreview`; shows a Notification "Applied — review
  and Save". **Nothing is persisted** until the user clicks Save.
- `discardSelfHeal(): void` — clears `$healPreview`.

## 4. Test-modal wiring (`StoreResource::testForm`)

In the existing "Results" `Section` (visible when `testScrapeResult` is filled):
- Add a header action **"Heal with AI"** beside "Compare with AI", visible only when
  `IntegrationHelper::isFeatureEnabled(AiFeature::Healing)`, calling `previewSelfHeal($get('test_url'))`.
- Add a heal-preview block, visible when `$healPreview` is filled: a
  `View::make('filament.resources.store-resource.heal-preview')` rendering the proposal, with
  **Apply to form** (`applySelfHeal()`) and **Discard** (`discardSelfHeal()`) actions.

## 5. Rendering (Blade)

New `resources/views/filament/resources/store-resource/heal-preview.blade.php`, modelled on
`test-results.blade.php`: a table with **field → proposed type + value → extracted value**,
and a note "Browser scraping required — applying will set the scraper service to Api" when
`usedBrowser` is true.

## 6. Gating, safety, UX

- The "Heal with AI" action is hidden unless the Healing feature is enabled
  (`isFeatureEnabled(AiFeature::Healing)`).
- Preview runs the agent synchronously (a few seconds) on user click — consistent with the
  existing synchronous "Compare with AI".
- Preview never writes to the DB; Apply only mutates in-memory form state; the user's
  explicit Save is the only persistence — so a wrong proposal is fully recoverable.
- Errors (no provider, fetch failure, agent returned nothing) surface as Notifications.

## 7. Testing

- **`previewForUrl` (feature, `AiService::runAgent` mocked):** returns
  `{fields, extracted, usedBrowser}` for a valid proposal; **creates/updates no store**
  (`assertDatabaseCount('stores', …)` unchanged); returns null when the Healing feature is
  disabled.
- **`healStoreForUrl` regression:** unchanged behaviour after the `runAgentForUrl` extraction
  (existing healer + bootstrap suites stay green).
- **EditStore Livewire:** with `AiConfigHealer` mocked to return a preview, `previewSelfHeal`
  populates `$healPreview` and the modal renders the proposal; `applySelfHeal` writes
  `data.scrape_strategy.*` (and `data.settings.scraper_service = api` when `usedBrowser`)
  **without persisting**; the "Heal with AI" action is hidden when healing is disabled.

## 8. Out of scope

- Per-field accept/reject (chosen: apply all at once; the user edits in the form afterward).
- Auto-persisting on apply (chosen: fill form → user Saves).
- A standalone heal page (chosen: integrated into the existing Test modal).
- Any change to the automatic background healing path beyond the shared-core refactor.
