# Deterministic-First Self-Healing (Schema.org / Auto-Create Heuristics) — Design

**Date:** 2026-06-09
**Status:** Approved (pending implementation plan)
**Builds on:** AI self-healing (`AiConfigHealer`, `HealingContext`), schema.org availability inference.

## 1. Problem & goal

When the self-healing agent heals a store whose page exposes schema.org JSON-LD (or og:
meta), it currently still runs the LLM and produces fragile per-field regexes/selectors.
Schema.org extraction is deterministic, and `AutoCreateStore` already prefers schema.org
(then selectors, then regex) for title/price/image — and it already accepts pre-fetched
HTML. The only reasons the heuristics weren't used: the healer goes straight to the AI
agent, and `AutoCreateStore` doesn't parse availability or expose extracted values.

Goal: in the self-healing flow, try the deterministic `AutoCreateStore` heuristics first —
on the static HTML, then on the browser-rendered HTML if static fails — and only invoke
the AI agent when the heuristics cannot build a config. Schema.org/og sites then heal with
**zero LLM tokens** and get clean `schema_org` strategies (including availability). The AI
agent remains the fallback for non-deterministic sites and keeps its own static→browser
switching.

## 2. `AutoCreateStore` changes

`AutoCreateStore::new(string $url, ?string $html = null, ...)` already builds its parser
from pre-fetched HTML when `$html` is supplied (no re-fetch), and `strategyParse()` tries
schema.org → selector → regex per `config('price_buddy.auto_create_store_strategies')`
(which has title/price/image only).

- **Add `parseAvailability(): array`** — schema.org only (`attemptSchemaOrg('availability')`);
  there is no reliable generic availability selector and the config has no availability
  candidates. Returns `['type' => 'schema_org', 'value' => null, 'data' => <url>]` when the
  JSON-LD offer has availability, else `[]`. Add `'availability' => $this->parseAvailability()`
  to `strategyParse()`. (`SchemaOrgService::parseSchemaOrg($schema, 'availability')` already
  returns the schema.org availability URL.)
- **Add `detect(): ?array`** returning a uniform result for callers that want both the
  selectors and the extracted values:
  `['fields' => [field => ['type'=>..., 'value'=>...]], 'extracted' => [field => <value>]]`,
  built from `strategyParse()` — including only fields whose parse produced a value, applying
  the existing title+price gate (return `null` if title or price is missing, matching
  `getStoreAttributes`'s gate). The `extracted` value comes from each parsed field's `data`.
- **Refactor `getStoreAttributes()`** to build on `detect()` (DRY): if `detect()` is null →
  null; else `buildAttributes($url, $detect['fields'])`. Behaviour unchanged for the existing
  create-store path (the new `availability` field flows through, which is an improvement).

## 3. Healer: `resolveConfigForUrl` (deterministic-first escalation)

Replace `AiConfigHealer::runAgentForUrl()` (currently: build context → fetch static if blank
→ run agent) with `resolveConfigForUrl(string $url, ?Store $store, ?string $html, AiProviderConfigDto $provider): ?array`
returning the uniform shape `['fields' => [...], 'extracted' => [...], 'usedBrowser' => bool]`
or null:

1. Build a `HealingContext($url, $store ?? transient, $html)`; ensure static HTML (fetch
   static when none was supplied).
2. `AutoCreateStore::new($url, $context->getHtml())->setLogErrors(false)->detect()` →
   non-null ⇒ return `['fields'=>…, 'extracted'=>…, 'usedBrowser'=>false]`.
3. Else `$context->fetch(true)` (browser) and `detect()` on the browser HTML → non-null ⇒
   return `[…, 'usedBrowser'=>true]`.
4. Else run `attemptAgentRepair($context, $provider)` (unchanged — the agent fetches and
   does its own static→browser switching). On non-null, return
   `['fields'=>$result['validated'], 'extracted'=>$result['extracted'], 'usedBrowser'=>$context->usedBrowser()]`;
   on null, return null.

`AutoCreateStore::detect()` runs on already-fetched HTML (no network in steps 2/3 beyond the
one browser fetch in step 3). `HealingContext::getHtml()` returns the full raw HTML, which is
what `AutoCreateStore` needs.

## 4. Consumers (behaviour unchanged, new source)

`heal()`, `healStoreForUrl()`, and `previewForUrl()` already call `runAgentForUrl` and consume
`['validated'/'extracted']` + `context->usedBrowser()`. They switch to `resolveConfigForUrl`
and consume the uniform `['fields','extracted','usedBrowser']`:

- `heal(Url, scrapeResult)` — apply `fields` to the existing store's `scrape_strategy`, set
  `scraper_service=api` when `usedBrowser`, clear cooldown, backfill `extracted` into
  `scrapeResult`. (Guards/lock/cooldown unchanged.)
- `healStoreForUrl(url, store, html)` — existing store: apply `fields` (+ api when browser).
  New domain: `CreateStoreAction(buildAttributes($url, $fields))` (+ api when browser).
- `previewForUrl(url, store, html)` — return `['fields','extracted','usedBrowser']` directly.

Net effect: the "Heal with AI" preview typically shows clean `schema_org` rows
(Title/Price/Image/Availability) for schema.org sites, with the agent never invoked.

## 5. Testing

- **`AutoCreateStore` (unit/feature):** `detect()` on a schema.org JSON-LD page (Product with
  name/price/image/offers.availability) returns all four fields as `type=schema_org` with the
  extracted values; on an og:-only page returns og selectors for title/price/image and no
  availability; on a page with neither title nor price returns null. `getStoreAttributes`
  still works (regression).
- **Healer deterministic path (feature):** with `AiService::runAgent` mocked to `->never()`,
  `healStoreForUrl` on a schema.org **browser-rendered** page (static blocked/empty) creates a
  store with `schema_org` strategy and `scraper_service=api`, **without** invoking the agent.
  A schema.org **static** page heals on http without browser. A page with no structured data
  falls through to the agent (mock returns a proposal) — existing agent path still works.
- **`previewForUrl` (feature):** schema.org page → preview `fields` are all `schema_org`,
  `usedBrowser` reflects whether the browser was needed, and `runAgent` is not called.
- Existing `AiConfigHealerTest` / `AiConfigHealerBootstrapTest` / `StoreSelfHealUiTest`
  continue to pass (their mocked-agent scenarios use non-structured HTML, so they still reach
  the agent fallback; where a test's HTML contains structured data it may now resolve
  deterministically — adjust those tests to use non-structured HTML, or assert the
  deterministic result, as appropriate).

## 6. Out of scope

- Adding a schema.org tool to the AI agent (chosen: heuristics handle schema.org; the agent
  is the non-deterministic fallback and keeps its current toolset + browser-switching).
- Changing the agent prompt or the agent's tools.
- Adding availability selector/regex candidates to the auto-create config (schema.org only).
- Renaming the "Heal with AI" button (it may now resolve deterministically without the LLM;
  acceptable as-is).
