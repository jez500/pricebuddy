<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Stores are shared, and user_id records who created a store. Stores from the
     * seeder, `stores:create` and queued jobs were saved with no creator. Give them
     * the oldest admin, else the oldest user. With no users, the stores stay NULL.
     */
    public function up(): void
    {
        $creatorId = DB::table('users')->where('role', 'admin')->orderBy('id')->value('id')
            ?? DB::table('users')->orderBy('id')->value('id');

        if ($creatorId === null) {
            return;
        }

        DB::table('stores')->whereNull('user_id')->update(['user_id' => $creatorId]);
    }

    /**
     * The original NULL values are not recorded, so this migration cannot be reversed.
     */
    public function down(): void
    {
        //
    }
};
