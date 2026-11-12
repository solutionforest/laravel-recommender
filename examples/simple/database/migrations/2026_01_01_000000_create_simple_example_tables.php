<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Example only: one item table + one table per interaction type.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('articles', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('author_id')->index();
            $table->string('title');
            $table->boolean('published')->default(true);
            $table->timestamps();
        });

        foreach (['article_views', 'article_likes'] as $name) {
            Schema::create($name, function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id');
                $table->unsignedBigInteger('article_id');
                $table->timestamp('created_at')->nullable()->index();
                $table->index(['user_id', 'created_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('article_likes');
        Schema::dropIfExists('article_views');
        Schema::dropIfExists('articles');
    }
};
