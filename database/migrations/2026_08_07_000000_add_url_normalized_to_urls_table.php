<?php

use App\Models\Url;
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
        Schema::table('urls', function (Blueprint $table) {
            $table->string('url_normalized', 255)->nullable()->after('url')->index();
        });

        // Backfill via the model helper rather than a second copy of the loop. The
        // coupling to Url::renormalizeAll() is deliberate: duplicating it here would
        // mean two call sites for the normalisation rule, which is what this feature
        // exists to avoid.
        Url::renormalizeAll();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('urls', function (Blueprint $table) {
            $table->dropIndex(['url_normalized']);
            $table->dropColumn('url_normalized');
        });
    }
};
