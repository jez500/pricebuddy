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
        Schema::table('products', function (Blueprint $table) {
            // Custom check frequency in seconds. Null = follow the global schedule.
            $table->unsignedInteger('refresh_interval')->nullable()->after('notify_in_stock');
            // When this product is next due for a check (only used with a custom interval).
            $table->timestamp('next_check_at')->nullable()->after('refresh_interval');
            // When true, the product is skipped by all scheduled checks.
            $table->boolean('paused')->default(false)->after('next_check_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['refresh_interval', 'next_check_at', 'paused']);
        });
    }
};
