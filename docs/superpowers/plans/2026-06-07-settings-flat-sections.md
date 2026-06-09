# Flatten settings sections Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Remove the nested `Section` cards inside the settings-page tabs (keeping each section's header + sub-header and fields), without touching the shared User profile page.

**Architecture:** Add an opt-in `flat` mode to the shared `makeSettingsSection` helper (returns a plain `Group` with a lightweight heading instead of a `Section` card) plus a `makeSettingsHeading` helper + blade. `AppSettingsPage` opts in everywhere (inline Scrape/Locale/Logging become flat groups; notification/AI/SearXNG pass `flat: true`). `UserResource` keeps the default `Section` behaviour. No field keys / state paths change.

**Tech Stack:** Laravel 12, Filament 3, Livewire, Pest/PHPUnit, Lando.

**Spec:** `docs/superpowers/specs/2026-06-07-settings-flat-sections-design.md`

---

## File structure

- `app/Filament/Traits/FormHelperTrait.php` — add `makeSettingsHeading()`; add `bool $flat = false` to `makeSettingsSection()`.
- `resources/views/components/settings_heading.blade.php` — new heading+sub-heading partial.
- `app/Filament/Pages/AppSettingsPage.php` — flatten the 3 inline sections; pass `flat: true` to the 9 `makeSettingsSection` callers; add `Group` import; update return types.
- Test: `tests/Feature/Filament/FormHelperTraitTest.php` (new), `tests/Feature/Filament/AppSettingsPageTest.php`.

---

## Task 1: `makeSettingsHeading` + opt-in `flat` on `makeSettingsSection`

**Files:**
- Modify: `app/Filament/Traits/FormHelperTrait.php`
- Create: `resources/views/components/settings_heading.blade.php`
- Test: `tests/Feature/Filament/FormHelperTraitTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Filament/FormHelperTraitTest.php`:
```php
<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\AppSettingsPage;
use Filament\Forms\Components\Group;
use Filament\Forms\Components\Section;
use Tests\TestCase;

class FormHelperTraitTest extends TestCase
{
    public function test_make_settings_section_returns_a_group_when_flat(): void
    {
        $component = AppSettingsPage::makeSettingsSection('Example', 'root', 'sub', [], 'desc', flat: true);

        $this->assertInstanceOf(Group::class, $component);
    }

    public function test_make_settings_section_returns_a_section_by_default(): void
    {
        $component = AppSettingsPage::makeSettingsSection('Example', 'root', 'sub', [], 'desc');

        $this->assertInstanceOf(Section::class, $component);
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `lando artisan test --compact --filter=FormHelperTraitTest`
Expected: FAIL — `makeSettingsSection` has no `flat` parameter (unknown named argument) / always returns `Section`.

- [ ] **Step 3: Add the heading blade**

Create `resources/views/components/settings_heading.blade.php`:
```blade
<div>
    <h3 class="text-base font-semibold leading-6 text-gray-950 dark:text-white">{{ $heading }}</h3>
    @if (filled($description ?? null))
        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ $description }}</p>
    @endif
</div>
```

- [ ] **Step 4: Add `makeSettingsHeading` + `flat` to the trait**

In `app/Filament/Traits/FormHelperTrait.php`, add the heading helper (next to `makeFormHeading`):
```php
    public static function makeSettingsHeading(string $heading, ?string $description = null): ViewField
    {
        return ViewField::make(Str::slug($heading).'-heading')
            ->view('components.settings_heading')
            ->viewData(['heading' => $heading, 'description' => $description]);
    }
```
Replace `makeSettingsSection` with the flat-aware version:
```php
    public static function makeSettingsSection(
        string $label,
        string $rootPath,
        string $subPath,
        array $schema = [],
        string|HtmlString|null $description = null,
        bool $flat = false,
    ): Section|Group {
        $inner = Group::make([
            Toggle::make($subPath.'.enabled')->reactive(),

            // Only make additional settings if schema exists.
            Group::make($schema)
                ->columns(2)
                ->statePath($subPath)
                ->hidden(fn ($get) => ! $get($subPath.'.enabled') || empty($schema))
                ->reactive(),
        ])->statePath($rootPath);

        if ($flat) {
            return Group::make([
                self::makeSettingsHeading($label, $description === null ? null : (string) $description),
                $inner,
            ]);
        }

        return Section::make($label)
            ->description($description)
            ->schema([$inner]);
    }
```
(`Section`, `Group`, `Toggle`, `ViewField`, `HtmlString`, `Str` are all already imported in this trait.)

- [ ] **Step 5: Run to verify it passes**

Run: `lando artisan test --compact --filter=FormHelperTraitTest`
Expected: PASS (2 tests).

- [ ] **Step 6: Confirm UserResource still works (default path unchanged)**

Run: `lando artisan test --compact --filter=UserTest`
Expected: PASS (UserResource uses the default `flat: false` → still `Section`).

- [ ] **Step 7: Pint + commit**
```bash
vendor/bin/pint --dirty
git add app/Filament/Traits/FormHelperTrait.php resources/views/components/settings_heading.blade.php tests/Feature/Filament/FormHelperTraitTest.php
git commit -m "feat: opt-in flat mode for settings sections + heading helper

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 2: Flatten the settings page sections

**Files:**
- Modify: `app/Filament/Pages/AppSettingsPage.php`
- Test: `tests/Feature/Filament/AppSettingsPageTest.php`

- [ ] **Step 1: Write the regression test**

Append to `tests/Feature/Filament/AppSettingsPageTest.php`:
```php
    public function test_settings_sections_render_their_headings(): void
    {
        Livewire::test(AppSettingsPage::class)
            ->assertSee('Scrape Settings')
            ->assertSee('Locale')
            ->assertSee('Logging')
            ->assertSee('Email');
    }
```
(Guards that the section headers survive the flatten — they now come from
`makeSettingsHeading` instead of the `Section` header, but the text is unchanged.)

- [ ] **Step 2: Run it (baseline)**

Run: `lando artisan test --compact --filter=test_settings_sections_render_their_headings`
Expected: PASS already (headings exist today via `Section`). This is a guard that
must stay green through the refactor.

- [ ] **Step 3: Add the `Group` import**

In `app/Filament/Pages/AppSettingsPage.php`, add to the `use` block:
```php
use Filament\Forms\Components\Group;
```

- [ ] **Step 4: Flatten the three inline sections**

Replace the three extracted methods so each returns a flat `Group`:
```php
    protected function getScrapeSection(): Group
    {
        return Group::make([
            self::makeSettingsHeading('Scrape Settings', __('Settings for scraping')),
            Group::make([
                TextInput::make('scrape_schedule')
                    ->label('Fetch schedule')
                    ->hintIcon(Icons::Help->value, 'Cron expression to control scraping. Use https://crontab.guru to build an expression.')
                    ->rule(new ValidCron)
                    ->live()
                    ->helperText(fn (Get $get) => ScheduleHelper::parseCronExpression($get('scrape_schedule')))
                    ->required(),
                TextInput::make('scrape_cache_ttl')
                    ->label('Scrape cache ttl')
                    ->hintIcon(Icons::Help->value, 'After a page is scraped, how many minutes will be the page html be cached for')
                    ->numeric()
                    ->minValue(1)
                    ->required(),
                TextInput::make('sleep_seconds_between_scrape')
                    ->label('Seconds to wait before fetching next page')
                    ->hintIcon(Icons::Help->value, 'It is recommended to wait a few seconds between fetching pages to prevent being blocked')
                    ->numeric()
                    ->minValue(1)
                    ->required(),
                TextInput::make('max_attempts_to_scrape')
                    ->label('Max scrape attempts')
                    ->hintIcon(Icons::Help->value, 'How many times to attempt to scrape a page before giving up')
                    ->numeric()
                    ->minValue(1)
                    ->required(),
            ])->columns(2),
        ]);
    }

    protected function getLocaleSection(): Group
    {
        return Group::make([
            self::makeSettingsHeading('Locale', __('Default region and locale settings')),
            Group::make(self::getLocaleFormFields('default_locale_settings'))->columns(2),
        ]);
    }

    protected function getLoggingSection(): Group
    {
        return Group::make([
            self::makeSettingsHeading('Logging', __('Settings for logging')),
            Group::make([
                Select::make('log_retention_days')
                    ->label('Log retention days')
                    ->options([
                        7 => '7 days',
                        14 => '14 days',
                        30 => '30 days',
                        90 => '90 days',
                        180 => '180 days',
                        365 => '365 days',
                    ])
                    ->hintIcon(Icons::Help->value, 'How many days to keep logs for')
                    ->required(),
            ])->columns(2),
        ]);
    }
```

- [ ] **Step 5: Pass `flat: true` to every `makeSettingsSection` caller + update return types**

In each of these methods in `AppSettingsPage` — `getEmailSettings`,
`getPushoverSettings`, `getGotifySettings`, `getAppriseSettings`,
`getTelegramSettings`, `getDiscordSettings`, `getNtfySettings`, `getSearXngSettings`,
`getAiSettings` — change the return type hint from `: Section` to `: Group`, and add
`flat: true` as the final argument to the `self::makeSettingsSection(...)` call.

For example, `getEmailSettings` becomes:
```php
    protected function getEmailSettings(): Group
    {
        return self::makeSettingsSection(
            'Email',
            self::NOTIFICATION_SERVICES_KEY,
            NotificationMethods::Mail->value,
            [
                // ... existing field schema unchanged ...
            ],
            __('SMTP settings for sending emails'),
            flat: true,
        );
    }
```
Apply the same two edits (return type `Group`, add `flat: true` after the
description argument) to all nine methods. Do not change any field definitions or
the description strings.

- [ ] **Step 6: Run the settings suites**

Run: `lando artisan test --compact --filter="AppSettingsPageTest|AppSettingsAiEncryptionTest|AppSettingsOllamaModelsTest"`
Expected: PASS — state paths unchanged, so `fillForm`/`save`/validation and the
heading-render guard all pass.

- [ ] **Step 7: Pint + commit**
```bash
vendor/bin/pint --dirty
git add app/Filament/Pages/AppSettingsPage.php tests/Feature/Filament/AppSettingsPageTest.php
git commit -m "feat: flatten settings page sections inside tabs (no nested cards)

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 3: Standards + full suite + manual check

**Files:** none (verification only)

- [ ] **Step 1: Standards** — Run: `lando phpcs-fix && lando phpcs` — expect Pint PASS then PHPStan `[OK] No errors`. (Pay attention to PHPStan on the new `Section|Group` union return type — if it flags a caller that strictly expects `Section`, none should remain on the settings page since all callers now pass `flat: true`; `UserResource` callers receive `Section` at runtime and don't type-narrow.)
- [ ] **Step 2: Full suite** — Run: `lando artisan test --parallel` — expect all green (re-run once if the known-flaky Telegram channel test trips under parallel load).
- [ ] **Step 3: Commit any standards fixes**
```bash
git add -A
git commit -m "style: phpcs fixes for flat settings sections"
```
(Skip if nothing changed.)

- [ ] **Step 4: Manual check (if Playwright MCP available)** — open `/admin` App Settings: confirm each tab now reads as a flat heading + sub-heading + fields with **no nested section cards** and reduced spacing; toggle a notification channel (e.g. Pushover) and confirm its fields still reveal/hide; confirm the User profile page still shows its notification sections as cards (unchanged). If the MCP is unavailable, note it; the automated tests cover the Group-vs-Section behaviour and persistence.

---

## Self-review notes

- **Spec §1 (makeSettingsHeading + blade):** Task 1 Steps 3–4.
- **Spec §2 (opt-in flat on makeSettingsSection):** Task 1 Step 4.
- **Spec §3 (AppSettingsPage uses flat — inline + makeSettingsSection callers):** Task 2 Steps 4–5.
- **Spec §2 header style (section-sized):** `settings_heading.blade.php` (`text-base font-semibold` + muted `text-sm` description).
- **Spec testing:** unit test flat→Group / default→Section (Task 1); UserResource unchanged (Task 1 Step 6); heading-render guard + persistence via existing tests (Task 2); phpcs + parallel + manual (Task 3).
- **Type consistency:** `makeSettingsSection(...): Section|Group`; the 9 caller methods + the 3 inline methods now return `Group`; `makeSettingsHeading(string, ?string): ViewField`; blade vars `heading`/`description`. State paths (`$rootPath`/`$subPath` groups, inline field keys) unchanged.
- **Ordering:** Task 1 is self-contained (trait/helper, default behaviour preserved → UserResource safe); Task 2 depends on `makeSettingsHeading` + `flat` from Task 1.
