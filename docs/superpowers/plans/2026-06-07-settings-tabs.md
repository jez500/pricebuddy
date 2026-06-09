# Tabbed settings page Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Reorganise the App Settings page into four mobile-friendly tabs (General, Scraping, Notifications, AI) with logically grouped sections — a pure layout regroup, no setting changes.

**Architecture:** Wrap `AppSettingsPage::form()`'s schema in a Filament `Tabs` component. Extract the three currently-inline `Section`s (Scrape, Locale, Logging) into private methods so each tab references named units; reuse the existing notification/AI/SearXNG section methods unchanged. Field state paths are untouched, so `save()`/persistence is unaffected.

**Tech Stack:** Laravel 12, Filament 3, Livewire, Pest/PHPUnit, Lando.

**Spec:** `docs/superpowers/specs/2026-06-07-settings-tabs-design.md`

---

## File structure

- `app/Filament/Pages/AppSettingsPage.php` — `form()` rewritten to use `Tabs`; three new private section methods; `Tabs` import; two `makeFormHeading(...)` calls removed.
- Test: `tests/Feature/Filament/AppSettingsPageTest.php`.

`makeFormHeading()` (in `FormHelperTrait`) stays — it's still used by `UserResource`.

---

## Task 1: Reorganise the settings form into tabs

**Files:**
- Modify: `app/Filament/Pages/AppSettingsPage.php`
- Test: `tests/Feature/Filament/AppSettingsPageTest.php`

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/Filament/AppSettingsPageTest.php`:
```php
    public function test_settings_page_renders_the_tabs(): void
    {
        Livewire::test(AppSettingsPage::class)
            ->assertSee('General')
            ->assertSee('Scraping')
            ->assertSee('Notifications')
            ->assertSee('AI');
    }

    public function test_a_general_tab_field_saves_without_error(): void
    {
        Livewire::test(AppSettingsPage::class)
            ->fillForm(['log_retention_days' => 90])
            ->call('save')
            ->assertHasNoFormErrors();
    }
```
(`test_settings_page_renders_the_tabs` fails today because the labels "General" and "Scraping" don't exist yet — current sections are "Scrape Settings" etc. The second test guards that a regrouped field still saves; the existing `test_max_priced_results_*` tests likewise cover a Scraping-tab field — SearXNG — saving through validation.)

- [ ] **Step 2: Run to verify it fails**

Run: `lando artisan test --compact --filter=test_settings_page_renders_the_tabs`
Expected: FAIL — "General"/"Scraping" not found.

- [ ] **Step 3: Add the `Tabs` import**

In `app/Filament/Pages/AppSettingsPage.php`, add to the `use` block (with the other `Filament\Forms\Components\*` imports):
```php
use Filament\Forms\Components\Tabs;
```

- [ ] **Step 4: Extract the three inline sections into private methods**

Add these three methods to the class (e.g. just below `form()`), moving the existing inline `Section` definitions verbatim:
```php
    protected function getScrapeSection(): Section
    {
        return Section::make('Scrape Settings')
            ->description(__('Settings for scraping'))
            ->columns(2)
            ->schema([
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
            ]);
    }

    protected function getLocaleSection(): Section
    {
        return Section::make('Locale')
            ->description(__('Default region and locale settings'))
            ->columns(2)
            ->schema(self::getLocaleFormFields('default_locale_settings'));
    }

    protected function getLoggingSection(): Section
    {
        return Section::make('Logging')
            ->description(__('Settings for logging'))
            ->columns(2)
            ->schema([
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
            ]);
    }
```

- [ ] **Step 5: Rewrite `form()` to use tabs**

Replace the entire body of `form()` with:
```php
    public function form(Form $form): Form
    {
        return $form->schema([
            Tabs::make('Settings')
                ->persistTabInQueryString()
                ->columnSpanFull()
                ->tabs([
                    Tabs\Tab::make('General')
                        ->icon('heroicon-o-cog-6-tooth')
                        ->schema([
                            $this->getLocaleSection(),
                            $this->getLoggingSection(),
                        ]),

                    Tabs\Tab::make('Scraping')
                        ->icon('heroicon-o-magnifying-glass')
                        ->schema([
                            $this->getScrapeSection(),
                            $this->getSearXngSettings(),
                        ]),

                    Tabs\Tab::make('Notifications')
                        ->icon('heroicon-o-bell')
                        ->schema([
                            $this->getEmailSettings(),
                            $this->getPushoverSettings(),
                            $this->getGotifySettings(),
                            $this->getAppriseSettings(),
                            $this->getTelegramSettings(),
                            $this->getDiscordSettings(),
                            $this->getNtfySettings(),
                        ]),

                    Tabs\Tab::make('AI')
                        ->icon('heroicon-o-sparkles')
                        ->schema([
                            $this->getAiSettings(),
                        ]),
                ]),
        ]);
    }
```
This removes the two `self::makeFormHeading('Notifications')` / `self::makeFormHeading('Integrations')` calls (the tabs replace them) and the inline Scrape/Locale/Logging definitions (now in the extracted methods).

- [ ] **Step 6: Run to verify it passes**

Run: `lando artisan test --compact --filter=AppSettingsPageTest`
Expected: PASS (the two new tests + the existing SearXNG validation tests, which exercise a Scraping-tab field).

Run the other settings suites to confirm no regression:
Run: `lando artisan test --compact --filter="AppSettingsAiEncryptionTest|AppSettingsOllamaModelsTest"`
Expected: PASS (state paths unchanged → AI/Ollama tests unaffected).

- [ ] **Step 7: Pint + commit**
```bash
vendor/bin/pint --dirty
git add app/Filament/Pages/AppSettingsPage.php tests/Feature/Filament/AppSettingsPageTest.php
git commit -m "feat: organise app settings into tabs (general, scraping, notifications, ai)

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 2: Standards + full suite + manual check

**Files:** none (verification only)

- [ ] **Step 1: Standards** — Run: `lando phpcs-fix && lando phpcs` — expect Pint PASS then PHPStan `[OK] No errors`.
- [ ] **Step 2: Full suite** — Run: `lando artisan test --parallel` — expect all green (re-run once if the known-flaky Telegram channel test trips under parallel load; confirm it passes in isolation).
- [ ] **Step 3: Commit any standards fixes**
```bash
git add -A
git commit -m "style: phpcs fixes for tabbed settings page"
```
(Skip if nothing changed.)

- [ ] **Step 4: Manual check (if Playwright MCP available)** — open `/admin` App Settings: confirm four tabs (General, Scraping, Notifications, AI) with the right sections under each; switch tabs; save and confirm you stay on the same tab (`persistTabInQueryString`); narrow the viewport to confirm the tab strip is usable on mobile. If the MCP is unavailable, note it; the automated tests cover tab rendering + persistence.

---

## Self-review notes

- **Spec mapping (General=Locale+Logging, Scraping=Scrape+SearXNG, Notifications=7 channels, AI):** Task 1 Step 5.
- **Section extraction:** Task 1 Step 4 (`getScrapeSection`/`getLocaleSection`/`getLoggingSection`, verbatim content).
- **Removed headings:** Task 1 Step 5 (both `makeFormHeading` calls dropped; method itself kept — still used by `UserResource`).
- **persistTabInQueryString + mobile-friendly:** Task 1 Step 5 (`->persistTabInQueryString()`; Filament `Tabs` is responsive by default).
- **Behaviour preserved / tests:** state paths unchanged; new tab-render + general-field-save tests, existing SearXNG/AI/Ollama tests guard persistence (Task 1 Steps 1/6); phpcs + parallel + manual (Task 2).
- **Type consistency:** new methods return `Section`; `Tabs::make(...)->tabs([Tabs\Tab::make(...)])`; existing section-method names (`getEmailSettings`, …, `getAiSettings`, `getSearXngSettings`) referenced exactly as defined.
