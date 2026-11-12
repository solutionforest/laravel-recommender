<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Advanced events sample: district + type on events, living district on users,
 * view / like / join interactions.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meetup_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('host_id')->index();
            $table->unsignedBigInteger('district_id')->index();
            $table->unsignedTinyInteger('type_id')->index(); // 1=outdoor, 2=workshop, …
            $table->string('title');
            $table->boolean('published')->default(true);
            $table->timestamp('starts_at')->nullable()->index();
            $table->timestamps();
        });

        foreach (['meetup_event_views', 'meetup_event_likes', 'meetup_event_joins'] as $table) {
            Schema::create($table, function (Blueprint $blueprint) {
                $blueprint->id();
                $blueprint->unsignedBigInteger('user_id')->index();
                $blueprint->unsignedBigInteger('event_id')->index();
                $blueprint->timestamp('created_at')->nullable()->index();
            });
        }

        Schema::create('user_districts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->unique();
            $table->unsignedBigInteger('district_id')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_districts');
        Schema::dropIfExists('meetup_event_joins');
        Schema::dropIfExists('meetup_event_likes');
        Schema::dropIfExists('meetup_event_views');
        Schema::dropIfExists('meetup_events');
    }
};
