<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $codes = range(0, 9999);
        if (DB::table('pos_products')->count() > count($codes)) {
            throw new RuntimeException('There are more products than available four-digit codes.');
        }
        shuffle($codes);

        Schema::create('pos_suppliers', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 150);
            $table->string('email', 254)->nullable();
            $table->string('phone', 40)->nullable();
            $table->timestamps();
        });

        Schema::table('pos_products', function (Blueprint $table): void {
            $table->string('internal_code', 4)->nullable()->unique();
            $table->string('barcode', 48)->nullable()->unique();
            $table->foreignId('supplier_id')->nullable()->constrained('pos_suppliers')->restrictOnDelete();
        });
        DB::table('pos_products')->select('id')->orderBy('id')->chunkById(250, function ($products) use (&$codes): void {
            foreach ($products as $product) {
                DB::table('pos_products')->where('id', $product->id)
                    ->update(['internal_code' => sprintf('%04d', array_pop($codes))]);
            }
        });
        Schema::table('pos_products', function (Blueprint $table): void {
            $table->string('internal_code', 4)->change();
        });
    }

    public function down(): void
    {
        Schema::table('pos_products', function (Blueprint $table): void {
            $table->dropForeign(['supplier_id']);
            $table->dropUnique(['internal_code']);
            $table->dropUnique(['barcode']);
            $table->dropColumn(['internal_code', 'barcode', 'supplier_id']);
        });
        Schema::dropIfExists('pos_suppliers');
    }
};
