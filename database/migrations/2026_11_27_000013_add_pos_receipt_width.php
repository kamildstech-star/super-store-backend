<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 80 mm retains the old layout's 72 mm printable-width cap.
        Schema::table('pos_store_settings', fn (Blueprint $table) => $table->unsignedTinyInteger('receipt_width_mm')->default(80));
    }

    public function down(): void
    {
        Schema::table('pos_store_settings', fn (Blueprint $table) => $table->dropColumn('receipt_width_mm'));
    }
};
