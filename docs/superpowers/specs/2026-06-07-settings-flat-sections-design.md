# Flatten settings sections (remove card nesting in tabs) — design

## Goal

The tabbed App Settings page nests `Section` cards inside the already-padded tab
panels, producing double spacing / heavy nesting. Remove the card chrome on the
settings page while keeping each section's header and sub-header (description) and
its fields. Scope: settings page only — the shared helper's default behaviour is
preserved so `UserResource` (non-tabbed) is untouched.

## Background — current state

- `App\Filament\Traits\FormHelperTrait`:
  - `makeFormHeading(string $heading): ViewField` → `components.form_heading`
    blade (a large `<h3 class="text-2xl font-bold ...">`). Used by `UserResource`
    ("Notification Settings"). Unchanged by this work.
  - `makeSettingsSection(string $label, string $rootPath, string $subPath, array $schema = [], string|HtmlString|null $description = null): Section`
    → a `Section::make($label)->description($description)->schema([ Group([ Toggle('enabled')->reactive(), Group($schema)->columns(2)->statePath($subPath)->hidden(when disabled)->reactive() ])->statePath($rootPath) ])`.
    Used by `AppSettingsPage` (Email/Pushover/Gotify/Apprise/Telegram/Discord/Ntfy,
    AI, SearXNG) **and** by `UserResource` (the 7 notification channels).
- `AppSettingsPage::form()` (already tabbed) has three inline `Section`s — Scrape
  Settings, Locale, Logging — plus the `makeSettingsSection`-based ones.

## Decisions

1. **Opt-in flatten.** `makeSettingsSection` gains a `bool $flat = false` param;
   only `AppSettingsPage` passes `flat: true`. `UserResource` keeps its `Section`
   cards (default `false`) — no change there.
2. **Header style** = section-sized: heading `text-base font-semibold` + muted
   sub-header `text-sm text-gray-500` (matches the old `Section` header/description
   without the card). The large `makeFormHeading` `<h3>` is left as-is.
3. **Behaviour preserved.** The `enabled` toggle and the hide-when-disabled
   conditional sub-schema stay; only the card wrapper is removed. No field keys or
   state paths change.

## Detailed design

### 1. `makeSettingsHeading` helper + blade

Add to `FormHelperTrait`:
```php
public static function makeSettingsHeading(string $heading, ?string $description = null): ViewField
{
    return ViewField::make(Str::slug($heading).'-heading')
        ->view('components.settings_heading')
        ->viewData(['heading' => $heading, 'description' => $description]);
}
```
New `resources/views/components/settings_heading.blade.php`:
```blade
<div>
    <h3 class="text-base font-semibold leading-6 text-gray-950 dark:text-white">{{ $heading }}</h3>
    @if (filled($description ?? null))
        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ $description }}</p>
    @endif
</div>
```
(The `-heading` suffix on the field key avoids collisions with a same-slug field.)

### 2. `makeSettingsSection` opt-in flat

Signature becomes:
```php
public static function makeSettingsSection(
    string $label,
    string $rootPath,
    string $subPath,
    array $schema = [],
    string|HtmlString|null $description = null,
    bool $flat = false,
): Section|Group
```
Build the inner content once (heading is added only when flat — the Section already
shows the label/description in non-flat mode):
```php
$inner = Group::make([
    Toggle::make($subPath.'.enabled')->reactive(),
    Group::make($schema)
        ->columns(2)
        ->statePath($subPath)
        ->hidden(fn ($get) => ! $get($subPath.'.enabled') || empty($schema))
        ->reactive(),
])->statePath($rootPath);

if ($flat) {
    return Group::make([
        self::makeSettingsHeading($label, $description instanceof HtmlString ? (string) $description : $description),
        $inner,
    ]);
}

return Section::make($label)->description($description)->schema([$inner]);
```
Note: the description for the flat heading is cast to string (the heading blade
escapes it); non-flat keeps the existing `->description($description)` which already
handles `HtmlString`. If any current caller passes an `HtmlString` description that
must render as HTML in flat mode, none currently do for the settings page (all
descriptions are plain strings), so string-casting is safe here.

### 3. AppSettingsPage uses flat

- The section methods that call `makeSettingsSection` (`getEmailSettings`,
  `getPushoverSettings`, `getGotifySettings`, `getAppriseSettings`,
  `getTelegramSettings`, `getDiscordSettings`, `getNtfySettings`, `getAiSettings`,
  `getSearXngSettings`) each pass `flat: true`. Their return type changes from
  `Section` to `Group` — update the method return type hints to `Group` (or the
  union `Section|Group`; `Group` is accurate since they always pass `flat: true`).
- The three inline sections become flat groups. Replace each
  `Section::make($title)->description($desc)->columns(2)->schema($fields)` with:
  ```php
  Group::make([
      self::makeSettingsHeading($title, $desc),
      Group::make($fields)->columns(2),
  ])
  ```
  Done in the extracted `getScrapeSection()`, `getLocaleSection()`,
  `getLoggingSection()` methods — change their return type from `Section` to `Group`.
- Add `use Filament\Forms\Components\Group;` to `AppSettingsPage` if not present.

The four `Tabs\Tab`s now contain `Group`s (flat heading + fields) instead of
`Section` cards, removing the in-tab card nesting and the double spacing.

## Testing

- **Unit (`FormHelperTrait`):** new `tests/Unit/Filament/FormHelperTraitTest.php`
  (or similar) — `makeSettingsSection(..., flat: true)` returns
  `Filament\Forms\Components\Group`; default (`flat: false`) returns
  `Filament\Forms\Components\Section` (guards `UserResource`).
- **Settings page still works:** existing `AppSettingsPageTest`,
  `AppSettingsAiEncryptionTest`, `AppSettingsOllamaModelsTest`,
  and the SearXNG validation tests stay green — state paths unchanged, so
  `fillForm`/`save`/`assertHasFormErrors` are unaffected. Confirm the page still
  renders the section headings (e.g. assert "Scrape Settings", "Locale", "Logging",
  "Email" headings appear).
- **UserResource unchanged:** its existing tests (and the default `Section` return)
  guard that the profile page keeps cards.
- `lando phpcs-fix && lando phpcs` to `[OK] No errors`; `lando artisan test --parallel`.
  Manual Playwright pass if available (confirm the tabs read as flat heading + fields
  with no nested cards / reduced spacing; toggling a notification channel still
  reveals its fields).

## Out of scope

- Changing `UserResource` / `makeFormHeading`.
- Any field/setting change or new validation.
- Restyling the tab strip itself.
