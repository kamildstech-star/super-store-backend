<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_products', function (Blueprint $table): void {
            $table->unsignedBigInteger('stock_milli')->default(0);
            $table->unsignedBigInteger('low_stock_milli')->default(0);
        });
        Schema::table('pos_orders', function (Blueprint $table): void {
            $table->dateTime('finalized_at')->nullable()->index();
            $table->string('payment_method', 20)->nullable();
            $table->uuid('checkout_id')->nullable();
            $table->foreignId('finalized_by')->nullable()->constrained('users')->restrictOnDelete();
        });
        Schema::create('pos_stock_movements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained('pos_products')->restrictOnDelete();
            $table->foreignId('supplier_id')->nullable()->constrained('pos_suppliers')->restrictOnDelete();
            $table->foreignId('order_id')->nullable()->constrained('pos_orders')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('actor', 255);
            $table->string('supplier_name', 150)->nullable();
            $table->string('type', 20);
            $table->uuid('request_id')->nullable();
            $table->bigInteger('delta_milli');
            $table->unsignedBigInteger('balance_milli');
            $table->string('reason', 240);
            $table->timestamps();
            $table->index(['product_id', 'id']);
            $table->unique(['order_id', 'product_id']);
            $table->unique(['product_id', 'request_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_stock_movements');
        Schema::table('pos_orders', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('finalized_by');
            $table->dropIndex(['finalized_at']);
            $table->dropColumn(['finalized_at', 'payment_method', 'checkout_id']);
        });
        Schema::table('pos_products', fn (Blueprint $table) => $table->dropColumn(['stock_milli', 'low_stock_milli']));
    }
};
