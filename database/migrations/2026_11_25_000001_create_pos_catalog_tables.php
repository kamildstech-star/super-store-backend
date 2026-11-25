<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_categories', function (Blueprint $table): void {
            $table->id();
            $table->string('slug', 48)->unique();
            $table->string('name', 100);
            $table->string('icon', 20);
            $table->string('color', 7);
            $table->unsignedSmallInteger('sort_order');
            $table->timestamps();
        });

        Schema::create('pos_units', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 16)->unique();
            $table->string('name', 40);
            $table->unsignedTinyInteger('quantity_precision')->default(0);
            $table->timestamps();
        });

        Schema::create('pos_products', function (Blueprint $table): void {
            $table->id();
            $table->string('sku', 40)->unique();
            $table->foreignId('category_id')->constrained('pos_categories')->restrictOnDelete();
            $table->foreignId('unit_id')->constrained('pos_units')->restrictOnDelete();
            $table->string('name', 150);
            $table->unsignedInteger('price_minor');
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order');
            $table->string('art_shape', 20);
            $table->string('art_ink', 7);
            $table->string('art_tone', 7);
            $table->string('art_label', 12)->default('');
            $table->index(['is_active', 'sort_order']);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_products');
        Schema::dropIfExists('pos_units');
        Schema::dropIfExists('pos_categories');
    }
};
