<?php

namespace App\Filament\Pages;

use App\Filament\Resources\ProductResource\Actions\CreateAction;
use App\Filament\Widgets\ProductStats;
use App\Services\Dashboard\DashboardLayoutService;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Toggle;
use Filament\Pages\Page;
use Filament\Support\Facades\FilamentIcon;
use Filament\Widgets\Widget;
use Filament\Widgets\WidgetConfiguration;
use Illuminate\Contracts\Support\Htmlable;

class HomeDashboard extends Page
{
    protected static string $routePath = '/';

    /**
     * Human labels for the toggleable dashboard sections.
     *
     * @var array<string, string>
     */
    private const SECTION_LABELS = [
        'stat_bar' => 'Summary stats',
        'buy_now' => "What's good to buy now",
        'recently_dropped' => 'Recently dropped',
        'needs_attention' => 'Needs attention',
    ];

    protected static ?int $navigationSort = -2;

    /**
     * @var view-string
     */
    protected static string $view = 'filament-panels::pages.dashboard';

    public static function getNavigationLabel(): string
    {
        return static::$navigationLabel ??
            static::$title ??
            __('filament-panels::pages/dashboard.title');
    }

    public static function getNavigationIcon(): string|Htmlable|null
    {
        return static::$navigationIcon
            ?? FilamentIcon::resolve('panels::pages.dashboard.navigation-item')
            ?? (Filament::hasTopNavigation() ? 'heroicon-m-home' : 'heroicon-o-home');
    }

    public static function getRoutePath(): string
    {
        return static::$routePath;
    }

    /**
     * @return array<class-string<Widget> | WidgetConfiguration>
     */
    public function getWidgets(): array
    {
        return [
            ProductStats::class,
        ];
    }

    /**
     * @return array<class-string<Widget> | WidgetConfiguration>
     */
    public function getVisibleWidgets(): array
    {
        return $this->filterVisibleWidgets($this->getWidgets());
    }

    /**
     * @return int | string | array<string, int | string | null>
     */
    public function getColumns(): int|string|array
    {
        return 1;
    }

    public function getTitle(): string|Htmlable
    {
        return static::$title ?? __('filament-panels::pages/dashboard.title');
    }

    public function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
            $this->customizeAction(),
        ];
    }

    protected function customizeAction(): Action
    {
        return Action::make('customize')
            ->label(__('Customize'))
            ->icon('heroicon-o-adjustments-horizontal')
            ->color('gray')
            ->modalHeading(__('Customize dashboard'))
            ->modalSubmitActionLabel(__('Save'))
            ->fillForm(fn (): array => $this->currentSectionVisibility())
            ->form(
                array_map(
                    fn (string $key): Toggle => Toggle::make($key)->label(__(self::SECTION_LABELS[$key])),
                    DashboardLayoutService::SECTION_KEYS,
                )
            )
            ->action(function (array $data): void {
                $layout = new DashboardLayoutService(auth()->user());

                foreach (DashboardLayoutService::SECTION_KEYS as $key) {
                    $layout->setSectionVisible($key, (bool) ($data[$key] ?? false));
                }

                $this->dispatch('dashboard-sections-updated');
            });
    }

    /**
     * @return array<string, bool>
     */
    protected function currentSectionVisibility(): array
    {
        $layout = new DashboardLayoutService(auth()->user());

        $visibility = [];
        foreach (DashboardLayoutService::SECTION_KEYS as $key) {
            $visibility[$key] = $layout->isSectionVisible($key);
        }

        return $visibility;
    }
}
