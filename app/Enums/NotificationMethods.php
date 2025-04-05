<?php

namespace App\Enums;

use App\Notifications\Channels\GotifyChannel;
use NotificationChannels\Pushover\PushoverChannel;

enum NotificationMethods: string
{
    case Mail = 'mail';

    case Database = 'database';

    case Pushover = 'pushover';
    case Gotify = 'gotify';

    public function getChannel(): string
    {
        return match ($this) {
            self::Pushover => PushoverChannel::class,
            self::Gotify => GotifyChannel::class,
            default => $this->value,
        };
    }

    public function requiresUserSettings(): bool
    {
        return match ($this) {
            self::Database => false,
            default => true,
        };
    }
}
