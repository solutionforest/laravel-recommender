<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Example only: catalog + three interaction tables (view / like / purchase).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('seller_id')->index();
            $table->string('title');
            $table->boolean('published')->default(true);
            $table->timestamps();
            $table->index('created_at');
        });

        foreach (['product_views', 'product_likes', 'product_purchases'] as $table) {
            Schema::create($table, function (Blueprint $blueprint) {
                $blueprint->id();
                $blueprint->unsignedBigInteger('user_id')->index();
                $blueprint->unsignedBigInteger('product_id')->index();
                $blueprint->timestamp('created_at')->nullable()->index();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('product_purchases');
        Schema::dropIfExists('product_likes');
        Schema::dropIfExists('product_views');
        Schema::dropIfExists('products');
    }
};
