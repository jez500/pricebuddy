<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('prices', function (Blueprint $table) {
            $table->float('scraped_price')->nullable()->after('price');
            $table->json('price_adjustments')->nullable()->after('price_factor');
        });

        $this->backfill();
    }

    /**
     * No store adjusted its prices before this, so every saved price is the page price.
     */
    public function backfill(): void
    {
        DB::table('prices')->whereNull('scraped_price')->update(['scraped_price' => DB::raw('price')]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('prices', function (Blueprint $table) {
            $table->dropColumn(['scraped_price', 'price_adjustments']);
        });
    }
};
