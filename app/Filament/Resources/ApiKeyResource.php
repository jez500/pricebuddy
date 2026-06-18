<?php

namespace App\Filament\Resources;

use App\Enums\ApiAbility;
use App\Filament\Resources\ApiKeyResource\Pages;
use Filament\Facades\Filament;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Illuminate\Support\Str;
use Rupadana\ApiService\ApiServicePlugin;
use Rupadana\ApiService\Resources\TokenResource;

class ApiKeyResource extends TokenResource
{
    protected static ?string $slug = 'api-keys';

    public static function getModelLabel(): string
    {
        return 'API key';
    }

    public static function getPluralModelLabel(): string
    {
        return 'API keys';
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Section::make('General')->schema([
                TextInput::make('name')
                    ->label('Name')
                    ->helperText('A label to help you recognise this key later.')
                    ->required()
                    ->maxLength(255),
                Radio::make('permission_mode')
                    ->label('Permissions')
                    ->options([
                        'all' => 'All — full access',
                        'custom' => 'Custom — choose specific permissions',
                    ])
                    ->descriptions([
                        'all' => 'Full access to every current and future endpoint.',
                        'custom' => 'Grant only the permissions you select below.',
                    ])
                    ->default('all')
                    ->required()
                    ->live(),
            ]),
            Section::make('Permissions')
                ->visible(fn (Get $get): bool => $get('permission_mode') === 'custom')
                ->schema(static::getPermissionSchema()),
        ]);
    }

    /**
     * @return array<int, Section>
     */
    public static function getPermissionSchema(): array
    {
        $sections = [];

        foreach (ApiServicePlugin::getAbilities(Filament::getCurrentPanel()) as $resource => $handlers) {
            $options = [];
            foreach ($handlers as $abilities) {
                foreach ($abilities as $ability) {
                    $options[$ability] = $ability;
                }
            }

            if ($options === []) {
                continue;
            }

            $withoutSuffix = Str::of($resource)->beforeLast('Resource');
            $className = $withoutSuffix->explode('\\')->last();
            $key = Str::of($className)->kebab()->value();

            $sections[] = Section::make(Str::of($key)->headline()->value())
                ->schema([
                    CheckboxList::make('abilities.'.$key)
                        ->hiddenLabel()
                        ->options($options)
                        ->bulkToggleable(),
                ])
                ->collapsible();
        }

        $customOptions = [];
        foreach (ApiAbility::cases() as $case) {
            $customOptions[$case->value] = $case->label();
        }

        $sections[] = Section::make('Custom endpoints')
            ->schema([
                CheckboxList::make('abilities.custom')
                    ->hiddenLabel()
                    ->options($customOptions)
                    ->bulkToggleable(),
            ])
            ->collapsible();

        return $sections;
    }

    /**
     * Every ability a custom key may be granted: the generated resource abilities plus
     * the custom-route abilities. Used to reject smuggled/unknown ability strings.
     *
     * @return array<int, string>
     */
    public static function knownAbilities(): array
    {
        $abilities = [];

        foreach (ApiServicePlugin::getAbilities(Filament::getCurrentPanel()) as $handlers) {
            foreach ($handlers as $list) {
                foreach ($list as $ability) {
                    $abilities[] = $ability;
                }
            }
        }

        foreach (ApiAbility::cases() as $case) {
            $abilities[] = $case->value;
        }

        return array_values(array_unique($abilities));
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListApiKeys::route('/'),
            'create' => Pages\CreateApiKey::route('/create'),
            'created' => Pages\ApiKeyCreated::route('/created'),
        ];
    }
}
