# AI Bootstrap/Repair Store Config on Product Create — Design

**Date:** 2026-06-07
**Status:** Approved (pending implementation plan)
**Builds on:** `2026-06-07-ai-self-healing-store-config-design.md`

## 1. Overview & goal

When creating a product (admin `/admin/products/create`, the API, or any caller of
`Url::createFromUrl()`), the create fails today when no usable store config exists for the
URL — either the heuristic `AutoCreateStore` couldn't infer selectors (no store row is
created) or a store exists but the scrape finds no price/title. The admin sees the
`ValidationException` "Unable to create product from this URL".

This feature adds an AI fallback: when a create would otherwise fail and AI healing is
enabled, invoke the existing healing agent to **build (bootstrap) or repair** the store's
`scrape_strategy`, then re-scrape once and continue. If the AI can't produce a working
config, behaviour is exactly as today (return `false`). The agent already knows how to
build and validate selectors from a page; the only new capability is creating a config
when **no store exists yet**.

The AI fallback runs whenever a working store is needed and missing — including when
adding a URL to an **existing product** (where `create_store` is unticked). The
`create_store` toggle continues to govern only the heuristic `AutoCreateStore`; the AI
fallback is an independent safety net gated solely by the global **Healing** feature (plus
the per-store opt-out for stores that already exist).

## 2. Trigger & flow (`Url::createFromUrl`)

The AI fallback is inserted on the existing failure path, runs synchronously, and
re-scrapes once on success:

```
if ($createStore) { AutoCreateStore::createStoreFromUrl($url); }   // unchanged heuristics
$scrape = ScrapeUrl::new($url)->scrape();
$store  = $scrape['store'];
$isUnavailable = StockStatus::matchFromScrapedValue($scrape['availability'], …)->isUnavailable();

// NEW: AI fallback when the create would otherwise fail.
if (! $store || (blank($scrape['price']) && ! $isUnavailable)) {
    $healed = AiConfigHealer::new()->healStoreForUrl($url, $store, $scrape['body']);
    if ($healed !== null) {
        $scrape = ScrapeUrl::new($url)->scrape();   // retry with the AI-built/repaired config
        $store  = $scrape['store'];
        $isUnavailable = StockStatus::matchFromScrapedValue($scrape['availability'], …)->isUnavailable();
    }
}

if (! $store || (blank($scrape['price']) && ! $isUnavailable)) {
    return false;   // unchanged fallback → ValidationException in the admin
}
// … unchanged: create product + url, updatePrice() …
```

AI runs **only on the failure path** — happy-path creates are untouched and incur no
token cost. `AutoCreateStore` still runs first; AI is the fallback, not the primary
extractor. After a successful heal, the re-scrape yields a price, so the later
`updatePrice()` (which also runs `AiConfigHealer::heal`) finds a price present and is a
no-op — no double agent run.

## 3. `AiConfigHealer` changes

### Extract a shared agent core

Add a private `attemptAgentRepair(string $url, Store $store, string $html, AiProviderConfigDto $provider): ?array`
that runs the agent (HealingContext + the three URL-bound tools + `AiService::runAgent`),
handles the "not a product" / null-proposal / provider-error cases (logging each, as
today), validates every proposable field against the page, and returns
`['validated' => [field => slot], 'extracted' => [field => value]]` when **price and title**
both validate, or `null` otherwise. This method is **pure** — it does not persist or set
cooldowns; callers decide what to do with the result. It emits the existing "attempt
started" and outcome logs.

The existing `heal(Url, scrapeResult)` is refactored to call `attemptAgentRepair` and keep
its current behaviour exactly (guards → core → on null: `markAiHealFailed` + return
untouched; on success: merge slots into the existing store, save, `clearAiHealFailed`,
backfill `scrapeResult`). Its existing tests guard against regression.

### New public method `healStoreForUrl(string $url, ?Store $store, ?string $html): ?Store`

1. Resolve the provider via `resolveFeatureProvider(AiFeature::Healing, $store)`; null →
   return null (global Healing feature off, or no provider).
2. If `$store` exists and `$store->ai_self_healing_disabled` → return null (respect opt-out).
3. Acquire a per-domain `Cache::lock` (keyed by host, or store id when present) so two
   concurrent creates of the same domain don't both run the agent; released in `finally`.
4. Context store: use `$store` if present; otherwise build a **transient, unsaved** `Store`
   from the URL host (minimal settings) purely as agent fetch-context. Validation does not
   use the store; fetch uses its cookies/scraper_options.
5. HTML: use the passed `$html` (already scraped); if blank, the agent's fetch tool can
   retrieve it.
6. Run `attemptAgentRepair`. On `null`:
   - existing store → `markAiHealFailed()` (24h cooldown, consistent with normal heal);
   - new (transient) store → persist nothing (no junk store left behind);
   - return null.
7. On success:
   - **existing store** → merge validated slots into `scrape_strategy`, save,
     `clearAiHealFailed()`, return the store;
   - **new store** → build store attributes from the URL host with the AI-built
     `scrape_strategy` and persist via the existing `CreateStoreAction`; return the store.

## 4. New-store creation (reuse existing attribute building)

A new AI-built store must be indistinguishable from a heuristic `AutoCreateStore` store
(domains `host` + `www.host`, `name` = ucfirst host, `settings` with `scraper_service`,
`test_url`, and locale). `AutoCreateStore::getStoreAttributes()` currently builds this but
is gated on heuristic strategy detection. Extract the attribute-assembly (everything after
the detection gate) into a reusable method — e.g.
`AutoCreateStore::buildAttributes(string $url, array $scrapeStrategy): array` — so both the
heuristic path and the AI path produce identical store shapes (DRY). `healStoreForUrl`
calls it with the AI strategy, then `CreateStoreAction`.

## 5. Gating, safety, logging

- Gated by the global **Healing** feature provider (`resolveFeatureProvider`/`isFeatureEnabled`)
  — the same setting governing self-healing — plus the per-store opt-out for existing stores.
- Synchronous, bounded by the agent's existing `MAX_STEPS`; reuses the same prompt,
  URL-bound tools (SSRF-safe), untrusted-HTML handling, and per-domain lock.
- Re-uses already-scraped HTML (no extra fetch on the common path).
- Logs the bootstrap attempt and outcome to the `db` channel, consistent with existing heal
  logging.
- On AI failure for a brand-new domain, nothing is persisted; the create returns `false`
  exactly as today.

## 6. Testing

- **`healStoreForUrl` (feature, AiService mocked):**
  - No existing store + valid AI proposal → a new `Store` is created with the AI
    `scrape_strategy`, correct domains/name, and returned.
  - Existing store with no price + valid proposal → its `scrape_strategy` is updated.
  - Global Healing disabled → returns null, no store created.
  - Existing store opted out (`ai_self_healing_disabled`) → returns null, unchanged.
  - AI proposal invalid/agent returns null → new domain persists **no** store (returns
    null); existing store gets the cooldown set.
- **`Url::createFromUrl` (feature, AiService mocked):**
  - A normally-failing create (no inferable store) succeeds once AI builds a config — a
    product + store + url are created and a price recorded.
  - Adding a URL to an **existing product** with no working store → AI builds the store and
    the url is attached.
  - AI disabled or AI fails → returns `false`, no store/product created (unchanged).
- **Filament `CreateProduct` (feature):**
  - Failing URL + AI enabled → product created (no `ValidationException`).
  - Failing URL + AI disabled → existing `ValidationException` "Unable to create product
    from this URL" unchanged.

## 7. Out of scope

- Asynchronous/queued healing on create (chosen: synchronous).
- Changing the `create_store` toggle semantics or the heuristic `AutoCreateStore` detection.
- Any UI beyond what already exists (no new settings; reuses the Healing feature gate).
