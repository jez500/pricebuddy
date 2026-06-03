<?php

namespace App\Models;

use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Carbon;

/**
 * Eloquent model over Laravel's `notifications` table, used to present a
 * browsable notification history. Sent notifications (price alerts, back-in-stock
 * alerts, scrape errors) are written here by the database notification channel.
 *
 * @property string $type
 * @property array $data
 * @property ?Carbon $read_at
 * @property Carbon $created_at
 */
class Notification extends DatabaseNotification
{
    // Table, uuid key and `data`/`read_at` casts are inherited from the parent.
}
