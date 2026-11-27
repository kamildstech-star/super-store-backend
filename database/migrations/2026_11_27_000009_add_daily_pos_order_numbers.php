<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_order_counters', function (Blueprint $table): void {
            $table->date('business_date')->primary();
            $table->unsignedBigInteger('last_number')->default(0);
        });
        Schema::table('pos_orders', function (Blueprint $table): void {
            $table->date('business_date')->nullable();
            $table->unsignedBigInteger('daily_number')->nullable();
            $table->unique(['business_date', 'daily_number']);
            $table->index('daily_number');
        });
    }

    public function down(): void
    {
        Schema::table('pos_orders', function (Blueprint $table): void {
            $table->dropUnique(['business_date', 'daily_number']);
            $table->dropIndex(['daily_number']);
            $table->dropColumn(['business_date', 'daily_number']);
        });
        Schema::dropIfExists('pos_order_counters');
    }
};
