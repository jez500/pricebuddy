<?php

namespace App\Filament\Resources;

use App\Filament\Resources\LogMessageResource\Pages;
use App\Providers\Filament\AdminPanelProvider;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Columns\Layout\Split;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Yoeriboven\LaravelLogDb\Models\LogMessage;

class LogMessageResource extends Resource
{
    protected static ?string $model = LogMessage::class;

    protected static ?string $navigationIcon = 'heroicon-o-information-circle';

    protected static ?string $navigationGroup = 'System';

    protected static ?int $navigationSort = 120;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                //
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Split::make([
                    Tables\Columns\Layout\Stack::make([
                        TextColumn::make('message')
                            ->sortable()
                            ->searchable(['message']),
                        TextColumn::make('context.url')
                            ->label('URL')
                            ->searchable(['url'])
                            ->sortable()
                            ->color('gray')
                            ->formatStateUsing(fn ($state) => new HtmlString('<span title="'.$state.'">'.Str::limit($state, 50).'</span>')),
                    ]),
                    TextColumn::make('logged_at')
                        ->sortable()
                        ->dateTime()
                        ->grow(false),
                ])->from('md'),
            ])
            ->paginated(AdminPanelProvider::DEFAULT_PAGINATION)
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
            ])
            ->defaultSort('logged_at', 'desc')
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListLogMessages::route('/'),
            'view' => Pages\ViewLogMessage::route('/{record}'),
        ];
    }
}
