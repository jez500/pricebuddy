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
        Schema::table('urls', function (Blueprint $table) {
            $table->float('factor')->default(1)->after('store_id');
        });

        Schema::table('prices', function (Blueprint $table) {
            $table->float('unit_price')->nullable()->after('price');
            $table->float('factor')->default(1)->after('unit_price');
        });

        // Populate existing prices: factor is 1, so unit_price = price.
        DB::table('prices')->update([
            'unit_price' => DB::raw('price'),
            'factor' => 1,
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('urls', function (Blueprint $table) {
            $table->dropColumn('factor');
        });

        Schema::table('prices', function (Blueprint $table) {
            $table->dropColumn(['unit_price', 'factor']);
        });
    }
};
