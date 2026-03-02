<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('urls', function (Blueprint $table) {
            $table->renameColumn('factor', 'price_factor');
        });

        Schema::table('prices', function (Blueprint $table) {
            $table->renameColumn('factor', 'price_factor');
        });
    }

    public function down(): void
    {
        Schema::table('urls', function (Blueprint $table) {
            $table->renameColumn('price_factor', 'factor');
        });

        Schema::table('prices', function (Blueprint $table) {
            $table->renameColumn('price_factor', 'factor');
        });
    }
};
