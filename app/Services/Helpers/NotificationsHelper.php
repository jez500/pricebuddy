<?php

namespace App\Services\Helpers;

use App\Enums\NotificationMethods;
use App\Models\User;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class NotificationsHelper
{
    public const string NOTIFICATION_SERVICES_KEY = 'notification_services';

    public static ?array $userSettings = null;

    public static function getServices(): Collection
    {
        return collect(SettingsHelper::getSetting(self::NOTIFICATION_SERVICES_KEY, []))
            ->each(fn ($service, $key) => NotificationMethods::tryFrom($key)
                ? true
                : throw new InvalidArgumentException(
                    sprintf('Invalid notification service: %s', $key)
                ) // Ensure the service is valid.
            )
            ->mapWithKeys(fn ($service, $key) => [
                // Merge the service user settings with app values like channel.
                $key => array_merge(
                    $service,
                    [
                        'channel' => NotificationMethods::tryFrom($key)->getChannel(),
                    ]
                ),
            ]);
    }

    public static function getUserServices(User $user): Collection
    {
        return collect(($user->settings['notifications'] ?? []));
    }

    public static function getEnabled(): Collection
    {
        return self::getServices()
            ->filter(fn ($service, $serviceName) => self::isEnabled($serviceName));
    }

    public static function getUserEnabled(User $user, string|NotificationMethods $service): bool
    {
        $service = self::getServiceName($service);

        return NotificationMethods::tryFrom($service)->requiresUserSettings()
            ? data_get(self::getUserServices($user)->toArray(), $service.'.enabled', false)
            : true;
    }

    public static function isEnabled(string|NotificationMethods $service): bool
    {
        return self::getSetting(self::getServiceName($service), 'enabled', false);
    }

    public static function getEnabledChannels(User $user): Collection
    {
        return self::getEnabled()
            ->filter(fn ($service, $serviceName) => self::getUserEnabled($user, $serviceName))
            ->pluck('channel');
    }

    public static function getSettings(string|NotificationMethods $service): array
    {
        return self::getServices()
            ->get(self::getServiceName($service)) ?? [];
    }

    public static function getSetting(string|NotificationMethods $service, string $name, mixed $default = null): mixed
    {
        return data_get(self::getSettings(self::getServiceName($service)), $name, $default);
    }

    public static function setSetting(string|NotificationMethods $service, string $name = '', mixed $value = null): void
    {
        SettingsHelper::setSetting(
            self::NOTIFICATION_SERVICES_KEY.'.'.self::getServiceName($service).($name ? '.'.$name : ''),
            $value
        );
    }

    public static function getServiceName(string|NotificationMethods $service): string
    {
        return is_string($service) ? $service : $service->value;
    }
}
