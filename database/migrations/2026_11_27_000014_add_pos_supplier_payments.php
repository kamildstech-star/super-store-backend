<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_purchase_receipts', function (Blueprint $table): void {
            $table->boolean('payment_tracking')->default(false);
            $table->unsignedBigInteger('paid_minor')->default(0);
        });
        Schema::create('pos_supplier_payments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('receipt_id')->constrained('pos_purchase_receipts')->restrictOnDelete();
            $table->uuid('request_id');
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('actor', 255);
            $table->unsignedBigInteger('amount_minor');
            $table->string('source', 10);
            $table->foreignId('cash_session_id')->nullable()->constrained('pos_cash_sessions')->restrictOnDelete();
            $table->foreignId('cash_movement_id')->nullable()->unique()->constrained('pos_cash_movements')->restrictOnDelete();
            $table->string('note', 160)->nullable();
            $table->dateTime('posted_at');
            $table->unique(['receipt_id', 'request_id'], 'pos_supplier_payment_request_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_supplier_payments');
        Schema::table('pos_purchase_receipts', fn (Blueprint $table) => $table->dropColumn(['payment_tracking', 'paid_minor']));
    }
};
