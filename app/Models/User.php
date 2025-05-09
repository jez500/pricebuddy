<?php

namespace App\Models;

use App\Enums\NotificationMethods;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Filament\Panel\Concerns\HasNotifications;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * @property string $name
 * @property string $email
 * @property array $settings
 */
class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, HasNotifications, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'settings',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'settings' => 'array',
        ];
    }

    public function stores(): HasMany
    {
        return $this->hasMany(Store::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    /**
     * Get notification setting(s) for a specific method.
     */
    public function getNotificationSettings(NotificationMethods $method, string $settingPath = '', mixed $default = null): mixed
    {
        return data_get(
            $this->settings,
            'notifications.'.$method->value.($settingPath ? '.'.$settingPath : ''),
            $default
        );
    }

    /**
     * Set Pushover user key.
     *
     * @see https://github.com/laravel-notification-channels/pushover?tab=readme-ov-file#advanced-usage-and-configuration
     *
     * Other notification channels may implement user settings differently.
     */
    public function routeNotificationForPushover()
    {
        return $this->getNotificationSettings(NotificationMethods::Pushover, 'user_key');
    }

    /**
     * All users can access panel.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return true;
    }
}
