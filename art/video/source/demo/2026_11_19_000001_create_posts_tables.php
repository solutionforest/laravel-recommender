<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->index();
            $table->string('title');
            $table->string('topic');
            $table->boolean('published')->default(true);
            $table->timestamps();
        });

        foreach (['post_likes', 'post_views'] as $name) {
            Schema::create($name, function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id');
                $table->foreignId('post_id');
                $table->timestamp('created_at')->nullable()->index();
                $table->index(['user_id', 'created_at']);
            });
        }
    }
};
