<?php

namespace App\Filament\Resources;

use App\Enums\NotificationMethods;
use App\Filament\Resources\UserResource\Pages;
use App\Filament\Traits\FormHelperTrait;
use App\Models\User;
use App\Providers\Filament\AdminPanelProvider;
use Filament\Forms;
use Filament\Forms\Components\Section;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Support\Enums\FontWeight;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Hash;

class UserResource extends Resource
{
    use FormHelperTrait;

    protected static ?string $model = User::class;

    protected static ?string $navigationGroup = 'System';

    protected static ?int $navigationSort = 110;

    protected static ?string $navigationIcon = 'heroicon-o-users';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('Account')
                    ->description('Manage account details.')
                    ->columns(2)
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->label('Name')
                            ->required(),
                        Forms\Components\TextInput::make('email')
                            ->label('Email')
                            ->email()
                            ->unique(ignoreRecord: true)
                            ->required(),
                        Forms\Components\TextInput::make('password')
                            ->label('Password')
                            ->password()
                            ->dehydrateStateUsing(fn ($state) => Hash::make($state))
                            ->dehydrated(fn ($state) => filled($state))
                            ->required(fn (string $context): bool => $context === 'create'),
                    ]),
                self::makeFormHeading('Notification Settings'),
                self::getEmailSettings(),
                self::getPushoverSettings(),
                self::getGotifySettings(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\Layout\Split::make([
                    Tables\Columns\TextColumn::make('name')
                        ->weight(FontWeight::Bold)
                        ->searchable(),
                    Tables\Columns\TextColumn::make('email')
                        ->searchable(),
                ])->from('sm'),
            ])
            ->paginated(AdminPanelProvider::DEFAULT_PAGINATION)
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
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
            'index' => Pages\ListUsers::route('/'),
            'create' => Pages\CreateUser::route('/create'),
            'edit' => Pages\EditUser::route('/{record}/edit'),
        ];
    }

    protected static function getEmailSettings(): Section
    {
        return self::makeSettingsSection(
            'Email',
            'settings.notifications',
            NotificationMethods::Mail->value
        );
    }

    protected static function getPushoverSettings(): Section
    {
        return self::makeSettingsSection(
            'Pushover',
            'settings.notifications',
            NotificationMethods::Pushover->value,
            [
                Forms\Components\TextInput::make('user_key')
                    ->label('User Key')
                    ->required(),
            ]
        );
    }

    protected static function getGotifySettings(): Section
    {
        return self::makeSettingsSection(
            'Gotify',
            'settings.notifications',
            NotificationMethods::Gotify->value,
            [
                Forms\Components\TextInput::make('url')
                    ->label('Gotify server URL')
                    ->placeholder('https://gotify.example.com')
                    ->required(),
                Forms\Components\TextInput::make('token')
                    ->label('Gotify token')
                    ->required(),
            ]
        );
    }
}
