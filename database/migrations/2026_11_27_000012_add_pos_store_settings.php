<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_store_settings', function (Blueprint $table): void {
            $table->unsignedTinyInteger('id')->primary();
            $table->string('name', 80);
            $table->string('address', 240)->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('receipt_footer', 180)->nullable();
            $table->unsignedInteger('revision')->default(1);
        });
        DB::table('pos_store_settings')->insert(['id' => 1, 'name' => 'Super Store']);

        foreach (['pos_orders', 'pos_refunds'] as $table) {
            Schema::table($table, fn (Blueprint $schema) => $schema->json('receipt_store')->nullable());
        }
    }

    public function down(): void
    {
        foreach (['pos_orders', 'pos_refunds'] as $table) {
            Schema::table($table, fn (Blueprint $schema) => $schema->dropColumn('receipt_store'));
        }
        Schema::dropIfExists('pos_store_settings');
    }
};
