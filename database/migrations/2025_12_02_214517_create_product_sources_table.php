<?php

use App\Enums\ProductSourceStatus;
use App\Models\Store;
use App\Models\User;
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
        Schema::create('product_sources', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('search_url');
            $table->string('type');
            $table->foreignIdFor(Store::class)->nullable()->constrained()->nullOnDelete();
            $table->json('extraction_strategy');
            $table->json('settings')->nullable();
            $table->string('status')->default(ProductSourceStatus::Active->value);
            $table->foreignIdFor(User::class)->nullable()->constrained()->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_sources');
    }
};
