# Tabbed settings page — design

## Goal

The App Settings page (`AppSettingsPage`) has grown into a long, flat,
unorganised scroll. Reorganise it into a small set of mobile-friendly tabs with
logically grouped sections. No setting is renamed, removed, or changed — this is
purely a layout regroup.

## Background — current state

`AppSettingsPage::form()` renders a flat schema:
- **Scrape Settings** section (`scrape_schedule`, `scrape_cache_ttl`,
  `sleep_seconds_between_scrape`, `max_attempts_to_scrape`)
- **Locale** section (`getLocaleFormFields('default_locale_settings')`)
- **Logging** section (`log_retention_days`)
- a `makeFormHeading('Notifications')` separator, then the notification channel
  sections: `getEmailSettings`, `getPushoverSettings`, `getGotifySettings`,
  `getAppriseSettings`, `getTelegramSettings`, `getDiscordSettings`,
  `getNtfySettings`
- a `makeFormHeading('Integrations')` separator, then `getAiSettings`,
  `getSearXngSettings`

Each section is a Filament `Section` (the notification/integration ones via
`makeSettingsSection`, which injects an `enabled` toggle). All fields write to
flat state paths bound by `save()`.

## Decisions

Four tabs (the originally-suggested "Advanced" tab is dropped; Logging moves into
General):

| Tab | Sections |
|---|---|
| **General** | Locale, Logging |
| **Scraping** | Scrape Settings, SearXNG |
| **Notifications** | Email, Pushover, Gotify, Apprise, Telegram, Discord, Ntfy |
| **AI** | AI providers |

## Detailed design

### Structure

Replace the flat `->schema([...])` in `AppSettingsPage::form()` with a single
`Tabs` component wrapping four `Tab`s:

```php
use Filament\Forms\Components\Tabs;

return $form->schema([
    Tabs::make('Settings')
        ->persistTabInQueryString()
        ->columnSpanFull()
        ->tabs([
            Tabs\Tab::make('General')
                ->icon('heroicon-o-cog-6-tooth')
                ->schema([
                    $this->getLocaleSection(),   // existing Locale Section, extracted
                    $this->getLoggingSection(),  // existing Logging Section, extracted
                ]),

            Tabs\Tab::make('Scraping')
                ->icon('heroicon-o-magnifying-glass')
                ->schema([
                    $this->getScrapeSection(),   // existing Scrape Settings Section, extracted
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
```

### Section extraction

The three sections currently defined inline in `form()` (Scrape Settings, Locale,
Logging) move into small private methods returning the same `Section` objects, so
the `form()` body stays readable and each tab references a named unit:

- `getScrapeSection(): Section` — the existing "Scrape Settings" `Section` verbatim.
- `getLocaleSection(): Section` — the existing "Locale" `Section` verbatim.
- `getLoggingSection(): Section` — the existing "Logging" `Section` verbatim.

The notification/integration/AI section methods (`getEmailSettings`, …,
`getAiSettings`, `getSearXngSettings`) are unchanged and referenced directly.

### Removed

- The two `makeFormHeading('Notifications')` / `makeFormHeading('Integrations')`
  calls (tabs replace these separators). Leave `makeFormHeading()` itself in place
  if it's used elsewhere; otherwise it becomes dead and can be removed — verify
  with a grep during implementation.

### Behaviour preserved

- All field state paths are unchanged → `save()`, `mount()`/form fill, and every
  setting persist exactly as before.
- `persistTabInQueryString()` keeps the active tab after a save (the page
  re-renders) and makes tabs shareable/linkable.
- **Mobile-friendly:** Filament `Tabs` is responsive by default — the tab strip
  scrolls horizontally on narrow screens and each tab's sections stack. No custom
  CSS required.

## Testing

- **Existing tests stay green:** `AppSettingsPageTest`, `AppSettingsAiEncryptionTest`,
  `AppSettingsOllamaModelsTest` all exercise `fillForm`/`assertFormSet`/`save`,
  which are unaffected by wrapping the schema in tabs (state paths unchanged).
- **New tabs render:** assert the page shows the four tab labels
  (`General`, `Scraping`, `Notifications`, `AI`).
- **Regroup didn't break persistence:** fill + save a field that now lives in a
  non-first tab (e.g. a Scraping field like `scrape_cache_ttl`, or a Notifications
  toggle) and assert it persisted — proving tabbing didn't change save behaviour.
- `lando phpcs-fix && lando phpcs` to `[OK] No errors`; `lando artisan test --parallel`.

## Out of scope

- Renaming, removing, or re-defaulting any setting.
- Changing `save()` / persistence.
- Per-tab access control or lazy-loading tabs.
