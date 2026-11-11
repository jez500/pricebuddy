<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->string('price_recalculation_run', 36)->nullable();
            $table->timestamp('price_recalculation_started_at')->nullable();
            $table->timestamp('price_recalculation_progress_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->dropColumn(['price_recalculation_run', 'price_recalculation_started_at', 'price_recalculation_progress_at']);
        });
    }
};
