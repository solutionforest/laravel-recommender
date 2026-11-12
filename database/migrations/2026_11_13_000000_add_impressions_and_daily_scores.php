<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 0.4: impressions (items shown) and per-day counters for quality over time.
 */
return new class extends Migration
{
    public function getConnection(): ?string
    {
        return config('recommender.database_connection');
    }

    public function up(): void
    {
        Schema::table('rec_item_scores', function (Blueprint $table) {
            $table->unsignedInteger('impressions')->default(0)->after('exposure'); // times shown in a page
            $table->timestamp('last_shown_at')->nullable()->after('last_event_at');
        });

        Schema::create('rec_item_daily', function (Blueprint $table) {
            $table->id();
            $table->string('feed', 64);
            $table->string('item_id', 64);
            $table->date('day');
            $table->unsignedInteger('positive')->default(0);
            $table->unsignedInteger('exposure')->default(0);
            $table->unsignedInteger('impressions')->default(0);

            $table->unique(['feed', 'item_id', 'day']);
            $table->index(['feed', 'day']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rec_item_daily');
        Schema::table('rec_item_scores', function (Blueprint $table) {
            $table->dropColumn(['impressions', 'last_shown_at']);
        });
    }
};
