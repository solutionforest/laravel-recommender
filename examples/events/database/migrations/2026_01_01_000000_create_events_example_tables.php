<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Example only: community events + view / like / join interactions.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('host_id')->index();
            $table->string('title');
            $table->boolean('published')->default(true);
            $table->timestamp('starts_at')->nullable()->index();
            $table->timestamps();
        });

        foreach (['event_views', 'event_likes', 'event_joins'] as $table) {
            Schema::create($table, function (Blueprint $blueprint) {
                $blueprint->id();
                $blueprint->unsignedBigInteger('user_id')->index();
                $blueprint->unsignedBigInteger('event_id')->index();
                $blueprint->timestamp('created_at')->nullable()->index();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('event_joins');
        Schema::dropIfExists('event_likes');
        Schema::dropIfExists('event_views');
        Schema::dropIfExists('events');
    }
};
