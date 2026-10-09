<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_features', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('product_id')->constrained();
            $table->string('key', 80);
            $table->string('name', 120);
            $table->string('category', 120)->nullable();
            $table->text('description')->nullable();
            $table->boolean('active')->default(true);
            $table->boolean('is_required')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['product_id', 'key']);
        });
        Schema::table('licenses', function (Blueprint $table) {
            $table->string('display_name', 120)->nullable();
            $table->string('edition', 80)->nullable();
            // Planning only: existing agents still install the complete runtime.
            $table->string('planned_feature_policy', 16)->default('all');
        });
        Schema::create('license_product_feature', function (Blueprint $table) {
            $table->foreignId('license_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_feature_id')->constrained()->restrictOnDelete();
            $table->primary(['license_id', 'product_feature_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('license_product_feature');
        Schema::table('licenses', fn (Blueprint $table) => $table->dropColumn(['display_name', 'edition', 'planned_feature_policy']));
        Schema::dropIfExists('product_features');
    }
};
