<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_purchase_receipts', function (Blueprint $table): void {
            $table->id();
            $table->uuid('request_id')->unique();
            $table->string('payload_hash', 64);
            $table->foreignId('supplier_id')->constrained('pos_suppliers')->restrictOnDelete();
            $table->string('supplier_name', 150);
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('actor', 255);
            $table->string('invoice_reference', 80)->nullable();
            $table->string('note', 240)->nullable();
            $table->unsignedBigInteger('total_minor')->default(0);
            $table->unsignedSmallInteger('line_count')->default(0);
            $table->timestamps();
            $table->index(['supplier_id', 'id']);
        });

        Schema::create('pos_purchase_receipt_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('receipt_id')->constrained('pos_purchase_receipts')->restrictOnDelete();
            $table->foreignId('product_id')->constrained('pos_products')->restrictOnDelete();
            $table->string('name', 150);
            $table->string('internal_code', 4)->nullable();
            $table->string('unit', 40);
            $table->unsignedBigInteger('quantity_milli');
            $table->unsignedBigInteger('unit_cost_minor');
            $table->unsignedBigInteger('total_minor');
            $table->unique(['receipt_id', 'product_id'], 'pos_receipt_line_product_unique');
        });

        Schema::table('pos_stock_movements', function (Blueprint $table): void {
            $table->foreignId('purchase_receipt_id')->nullable()->constrained('pos_purchase_receipts')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('pos_stock_movements', fn (Blueprint $table) => $table->dropConstrainedForeignId('purchase_receipt_id'));
        Schema::dropIfExists('pos_purchase_receipt_lines');
        Schema::dropIfExists('pos_purchase_receipts');
    }
};
