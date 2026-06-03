<?php

namespace App\Filament\Resources;

use App\Filament\Resources\NotificationHistoryResource\Pages;
use App\Models\Notification;
use App\Models\User;
use App\Notifications\PriceAlertNotification;
use App\Notifications\ScrapeFailNotification;
use App\Notifications\StockAlertNotification;
use App\Providers\Filament\AdminPanelProvider;
use Filament\Resources\Resource;
use Filament\Support\Enums\FontWeight;
use Filament\Tables;
use Filament\Tables\Columns\Layout\Split;
use Filament\Tables\Columns\Layout\Stack;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class NotificationHistoryResource extends Resource
{
    protected static ?string $model = Notification::class;

    // Accessed via the user menu rather than the sidebar (see AdminPanelProvider).
    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $navigationIcon = 'heroicon-o-bell';

    protected static ?string $navigationGroup = 'System';

    protected static ?string $navigationLabel = 'Notifications';

    protected static ?string $modelLabel = 'Notification';

    protected static ?string $pluralModelLabel = 'Notifications';

    protected static ?int $navigationSort = 115;

    /**
     * The PriceBuddy notification types shown in the history, with display meta.
     *
     * @return array<class-string, array{label: string, color: string, icon: string}>
     */
    public static function typeMeta(): array
    {
        return [
            PriceAlertNotification::class => ['label' => 'Price alert', 'color' => 'success', 'icon' => 'heroicon-m-tag'],
            StockAlertNotification::class => ['label' => 'Back in stock', 'color' => 'success', 'icon' => 'heroicon-m-check-circle'],
            ScrapeFailNotification::class => ['label' => 'Scrape error', 'color' => 'warning', 'icon' => 'heroicon-m-exclamation-triangle'],
        ];
    }

    /**
     * Only show the current user's PriceBuddy notifications.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('notifiable_type', User::class)
            ->where('notifiable_id', auth()->id())
            ->whereIn('type', array_keys(self::typeMeta()));
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Split::make([
                    TextColumn::make('type')
                        ->label('Type')
                        ->badge()
                        ->grow(false)
                        ->formatStateUsing(fn ($state) => self::typeMeta()[$state]['label'] ?? class_basename($state))
                        ->color(fn ($state) => self::typeMeta()[$state]['color'] ?? 'gray')
                        ->icon(fn ($state) => self::typeMeta()[$state]['icon'] ?? null),

                    Stack::make([
                        TextColumn::make('title')
                            ->weight(FontWeight::Bold)
                            ->getStateUsing(fn (Notification $record) => data_get($record->data, 'title'))
                            ->wrap(),
                        TextColumn::make('body')
                            ->color('gray')
                            ->getStateUsing(fn (Notification $record) => data_get($record->data, 'body'))
                            ->wrap(),
                    ]),

                    TextColumn::make('created_at')
                        ->label('When')
                        ->since()
                        ->dateTimeTooltip()
                        ->sortable()
                        ->grow(false),
                ])->from('md'),
            ])
            ->filters([
                SelectFilter::make('type')
                    ->label('Type')
                    ->options(collect(self::typeMeta())->map(fn ($meta) => $meta['label'])->all())
                    ->native(false),
                TernaryFilter::make('read_at')
                    ->label('Status')
                    ->placeholder('All')
                    ->trueLabel('Read')
                    ->falseLabel('Unread')
                    ->queries(
                        true: fn (Builder $query) => $query->whereNotNull('read_at'),
                        false: fn (Builder $query) => $query->whereNull('read_at'),
                        blank: fn (Builder $query) => $query,
                    ),
            ])
            ->paginated(AdminPanelProvider::DEFAULT_PAGINATION)
            ->defaultSort('created_at', 'desc')
            ->actions([
                Tables\Actions\Action::make('open')
                    ->label('Open')
                    ->icon('heroicon-m-arrow-top-right-on-square')
                    ->url(fn (Notification $record) => self::productUrl($record))
                    ->visible(fn (Notification $record) => filled(self::productUrl($record))),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateHeading(__('No notifications yet'))
            ->emptyStateIcon('heroicon-o-bell-slash');
    }

    /**
     * Extract the internal "view product" link stored on the notification, if any.
     */
    protected static function productUrl(Notification $record): ?string
    {
        $actions = data_get($record->data, 'actions', []);

        return collect($actions)->firstWhere('name', 'view')['url'] ?? null;
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListNotifications::route('/'),
        ];
    }
}
