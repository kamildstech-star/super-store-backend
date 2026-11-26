<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_orders', function (Blueprint $table): void {
            $table->unsignedBigInteger('cash_received_minor')->nullable();
            $table->string('finalized_cashier')->nullable();
            $table->char('request_fingerprint', 64)->nullable();
            $table->char('checkout_fingerprint', 64)->nullable();
            $table->uuid('update_request_id')->nullable();
            $table->char('update_fingerprint', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('pos_orders', fn (Blueprint $table) => $table->dropColumn([
            'cash_received_minor', 'finalized_cashier', 'request_fingerprint',
            'checkout_fingerprint', 'update_request_id', 'update_fingerprint',
        ]));
    }
};
