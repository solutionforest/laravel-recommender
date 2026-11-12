<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function getConnection(): ?string
    {
        return config('recommender.database_connection');
    }

    public function up(): void
    {
        // One row per recently active item per feed (prune drops idle items).
        // Size follows the number of active items, not traffic.
        Schema::create('rec_item_scores', function (Blueprint $table) {
            $table->id();
            $table->string('feed', 64);
            $table->string('item_id', 64);
            $table->double('hot')->nullable();               // log-space time-decayed popularity
            $table->unsignedInteger('positive')->default(0);  // e.g. likes
            $table->unsignedInteger('exposure')->default(0);  // e.g. views
            $table->timestamp('last_event_at')->nullable();
            $table->timestamp('updated_at')->nullable();

            $table->unique(['feed', 'item_id']);
            $table->index(['feed', 'hot']);
            $table->index(['feed', 'last_event_at']);   // prune
        });

        // One row per item per build version: its top-N similar items packed as JSON.
        // Old versions are deleted after every successful build.
        Schema::create('rec_item_neighbours', function (Blueprint $table) {
            $table->id();
            $table->string('feed', 64);
            $table->unsignedBigInteger('version');
            $table->string('item_id', 64);
            $table->text('neighbours');                       // [[id, similarity], ...]

            $table->unique(['feed', 'version', 'item_id']);
        });

        // Small key/value table for job watermarks and the active neighbour version.
        Schema::create('rec_state', function (Blueprint $table) {
            $table->string('key', 191)->primary();
            $table->text('value')->nullable();
            $table->timestamp('updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rec_state');
        Schema::dropIfExists('rec_item_neighbours');
        Schema::dropIfExists('rec_item_scores');
    }
};
