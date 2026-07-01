<?php

namespace App\Services\Dashboard;

use App\Models\User;

class DashboardLayoutService
{
    public const SECTION_KEYS = ['stat_bar', 'buy_now', 'recently_dropped', 'needs_attention'];

    /**
     * Default visibility per section for users with no stored preference.
     *
     * @var array<string, bool>
     */
    private const DEFAULT_VISIBILITY = [
        'stat_bar' => false,
        'buy_now' => true,
        'recently_dropped' => false,
        'needs_attention' => false,
    ];

    public function __construct(private readonly User $user) {}

    /**
     * @return array<int, array{key: string, visible: bool}>
     */
    public function sections(): array
    {
        $stored = data_get($this->user->settings, 'dashboard.sections', []);

        return collect(self::SECTION_KEYS)
            ->map(fn (string $key): array => [
                'key' => $key,
                'visible' => (bool) data_get($stored, $key.'.visible', $this->defaultVisibility($key)),
            ])
            ->all();
    }

    public function isSectionVisible(string $key): bool
    {
        return (bool) data_get($this->user->settings, "dashboard.sections.$key.visible", $this->defaultVisibility($key));
    }

    /**
     * @return array<int, string>
     */
    public function categoryOrder(): array
    {
        return array_values((array) data_get($this->user->settings, 'dashboard.categories.order', []));
    }

    public function isCategoryCollapsed(string $signature): bool
    {
        return in_array($signature, (array) data_get($this->user->settings, 'dashboard.categories.collapsed', []), true);
    }

    public function toggleSection(string $key): void
    {
        if (! in_array($key, self::SECTION_KEYS, true)) {
            return;
        }

        $current = (bool) data_get($this->user->settings, "dashboard.sections.$key.visible", $this->defaultVisibility($key));
        $this->write("dashboard.sections.$key.visible", ! $current);
    }

    private function defaultVisibility(string $key): bool
    {
        return self::DEFAULT_VISIBILITY[$key] ?? true;
    }

    public function toggleCategoryCollapse(string $signature): void
    {
        $collapsed = (array) data_get($this->user->settings, 'dashboard.categories.collapsed', []);

        $collapsed = in_array($signature, $collapsed, true)
            ? array_values(array_diff($collapsed, [$signature]))
            : [...$collapsed, $signature];

        $this->write('dashboard.categories.collapsed', $collapsed);
    }

    /**
     * @param  array<int, string>  $signatures
     */
    public function setCategoryOrder(array $signatures): void
    {
        $this->write('dashboard.categories.order', array_values($signatures));
    }

    private function write(string $path, mixed $value): void
    {
        $settings = $this->user->settings ?? [];
        data_set($settings, $path, $value);
        $this->user->settings = $settings;
        $this->user->save();
    }
}
