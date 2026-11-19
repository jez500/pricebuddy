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
        Schema::create('exchange_rates', function (Blueprint $table) {
            $table->id();
            // The provider the rate came from, so switching provider never mixes data.
            $table->string('source', 32);
            // 1 unit of base_currency = rate units of quote_currency.
            $table->char('base_currency', 3);
            $table->char('quote_currency', 3);
            $table->date('date');
            $table->decimal('rate', 20, 10);
            $table->timestamps();

            $table->unique(['source', 'base_currency', 'quote_currency', 'date'], 'exchange_rates_pair_date_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('exchange_rates');
    }
};
