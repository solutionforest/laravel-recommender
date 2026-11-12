<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tables for DatabaseInteractionsTest. Loaded as a migration so they are
 * rolled back after each test (DDL is not transactional on MySQL).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('video_plays', function (Blueprint $t) {
            $t->id();
            $t->uuid('viewer_uuid');
            $t->uuid('video_uuid');
            $t->timestamp('played_at');
        });
        Schema::create('video_favourites', function (Blueprint $t) {
            $t->id();
            $t->uuid('viewer_uuid');
            $t->uuid('clip_uuid');
            $t->timestamp('played_at');
        });
        Schema::create('fixture_article_likes', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id');
            $t->unsignedBigInteger('article_id');
            $t->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fixture_article_likes');
        Schema::dropIfExists('video_favourites');
        Schema::dropIfExists('video_plays');
    }
};
