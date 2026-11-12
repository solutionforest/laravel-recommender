<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Full copy of the events example tables, plus district / type / living district / comments.
 * Standalone — do not run alongside examples/events in the same DB.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('host_id')->index(); // hosting party / organiser
            $table->unsignedBigInteger('district_id')->index();
            $table->unsignedTinyInteger('type_id')->index(); // 1=outdoor, 2=workshop, …
            $table->string('title');
            $table->boolean('published')->default(true);
            $table->timestamp('starts_at')->nullable()->index();
            $table->timestamps();
        });

        foreach (['event_views', 'event_likes', 'event_joins', 'event_comments'] as $table) {
            Schema::create($table, function (Blueprint $blueprint) use ($table) {
                $blueprint->id();
                $blueprint->unsignedBigInteger('user_id')->index();
                $blueprint->unsignedBigInteger('event_id')->index();
                if ($table === 'event_comments') {
                    $blueprint->text('body')->nullable();
                }
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
        Schema::dropIfExists('event_comments');
        Schema::dropIfExists('event_joins');
        Schema::dropIfExists('event_likes');
        Schema::dropIfExists('event_views');
        Schema::dropIfExists('events');
    }
};
