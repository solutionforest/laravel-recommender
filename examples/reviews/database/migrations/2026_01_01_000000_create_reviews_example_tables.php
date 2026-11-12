<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Example only: shops + reviews + likes/views + user pets. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shops', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->boolean('is_opening')->default(true);
            $table->timestamps();
        });

        Schema::create('reviews', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();
            $table->unsignedBigInteger('shop_id')->index();
            $table->string('title');
            $table->boolean('published')->default(true);
            $table->string('cover_url')->nullable();
            $table->timestamps();
            $table->index('created_at');
        });

        Schema::create('review_likes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();
            $table->unsignedBigInteger('review_id')->index();
            $table->timestamp('created_at')->nullable()->index();
        });

        Schema::create('review_views', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();
            $table->unsignedBigInteger('review_id')->index();
            $table->timestamp('created_at')->nullable()->index();
        });

        Schema::create('user_pets', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();
            $table->unsignedTinyInteger('pet_type_id')->index(); // 1=dog, 2=cat (example)
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_pets');
        Schema::dropIfExists('review_views');
        Schema::dropIfExists('review_likes');
        Schema::dropIfExists('reviews');
        Schema::dropIfExists('shops');
    }
};
