<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_products', function (Blueprint $table): void {
            $table->unsignedInteger('retail_price_minor')->nullable();
        });

        DB::table('pos_products')->whereNull('retail_price_minor')
            ->update(['retail_price_minor' => DB::raw('price_minor')]);
    }

    public function down(): void
    {
        Schema::table('pos_products', fn (Blueprint $table) => $table->dropColumn('retail_price_minor'));
    }
};
