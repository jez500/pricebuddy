---
title: "Fix #196: Potential Savings stat uses configured currency"
created: 2026-08-09
artifact_contract: ce-unified-plan/v1
artifact_readiness: implementation-ready
execution: code
product_contract_source: ce-plan-bootstrap
plan_model: session
---

# Fix #196: "Potential Savings" stat currency hardcoded to dollars

## Problem Frame

The dashboard "Potential savings" stat always renders a `$` prefix regardless of the
user's configured locale/currency. The savings figure itself is computed correctly in
the user's default currency, so a user on EUR/GBP/AUD sees a mislabeled value.

## Goal Capsule

Make the "Potential savings" dashboard stat format the savings value with the
configured default currency and locale, consistent with every other price display in
the app (e.g. min/avg/max aggregates, product cards).

## Scope

**In scope**

- The dashboard stat cell rendered by `resources/views/filament/widgets/dashboard/stat-bar.blade.php` (Potential savings cell only).
- A regression test asserting non-USD configuration renders the configured currency.

**Out of scope**

- Other hardcoded or stale-currency displays elsewhere (e.g. `PriceCacheDto` cached-currency mismatch, issue #124) — separate issue, not addressed here.
- Store-level currency handling.

## Success Criteria

- With `default_locale_settings.currency` set to a non-USD currency, the Potential
  savings cell renders that currency's symbol/ISO formatting, not `$`.
- With default USD settings, output is equivalent in meaning to before (a USD-formatted value).
- Existing dashboard tests still pass.

## Decisions

- **D1 — Format via `CurrencyHelper::toString`** (governs unit 1). The app already
  formats every other price display with `CurrencyHelper::toString(mixed $value, int $maxPrecision = 2, ?string $locale = null, ?string $iso = null)`
  (see `app/Services/Helpers/CurrencyHelper.php:80`, which delegates to
  `Illuminate\Support\Number::currency` with the configured default locale/currency).
  Rejected: keeping `$`.number_format (wrong symbol); formatting in the widget class
  `DashboardSections` (the view layer already receives a numeric value, and other stat
  cells pass raw numbers to the view — keep the cell source unchanged).
- **D2 — Pattern: `@php use` import in the blade.** Follow the existing
  `resources/views/body/js-settings.blade.php:2` pattern of importing
  `App\Services\Helpers\CurrencyHelper` inside a `@php` block rather than FQCN inline.

## Implementation Units

### Unit 1 — Format Potential savings with configured currency

**Files**

- `resources/views/filament/widgets/dashboard/stat-bar.blade.php` (modified)

**Change**

Add a `@php` block importing `App\Services\Helpers\CurrencyHelper`, and change the
"Potential savings" cell value from:

```php
'$'.number_format($stats['potentialSavings'], 2)
```

to:

```php
CurrencyHelper::toString($stats['potentialSavings'])
```

The other four cells (counts) remain unchanged; `$stats` structure is unchanged.

**Test scenarios** (`tests/Feature/View/StatBarTest.php`, new)

1. `test_potential_savings_uses_configured_currency`: set
   `SettingsHelper`/settings `default_locale_settings.currency` to a non-USD currency
   (e.g. `EUR`) and `default_locale_settings.locale` to a matching locale (e.g.
   `en_IE`), render `view('filament.widgets.dashboard.stat-bar', ['stats' => ['tracked' => 1, 'atLowest' => 1, 'belowAverage' => 1, 'outOfStock' => 0, 'potentialSavings' => 123.45]])`,
   assert the output contains the EUR symbol (e.g. `€`) and does not contain `$123.45`.
2. `test_potential_savings_usd_default_renders_dollar_value`: with default settings,
   render the same view and assert the formatted USD value appears.

Follow the existing view-test pattern in `tests/Feature/View/ProductBadgesTest.php`
(`$this->blade()` / `view()` + `assertSee`).

## Dependencies & Sequencing

- Unit 1 is the only unit; no sequencing constraints.
- Run `php artisan test --filter=StatBarTest` plus the existing dashboard test group
  (`tests/Feature/Filament/HomeDashboardTest.php`, `tests/Feature/Widgets/ProductStatsTest.php`,
  `tests/Feature/Services/Dashboard/DashboardSectionsTest.php`) to confirm no regression.

## Risks

- **Currency symbol vs ISO code rendering**: `Number::currency` may render
  "€1,234.50" or "EUR 1,234.50" depending on locale. The test asserts the EUR symbol
  under `en_IE`; if the harness renders differently for that locale, assert on the
  locale-formatted string instead. Low risk — behavior is delegated to the same
  helper every other display uses.

## References

- Origin: https://github.com/jez500/pricebuddy/issues/196
- Offending line: `resources/views/filament/widgets/dashboard/stat-bar.blade.php:7`
- Savings source: `app/Services/Dashboard/DashboardSections.php:66`
