<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_cash_sessions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('open_key')->unique();
            $table->uuid('close_key')->nullable()->unique();
            $table->foreignId('opened_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('closed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('opener');
            $table->string('closer')->nullable();
            $table->unsignedBigInteger('opening_minor');
            $table->bigInteger('sales_minor')->default(0);
            $table->unsignedBigInteger('refunds_minor')->default(0);
            $table->unsignedBigInteger('cash_in_minor')->default(0);
            $table->unsignedBigInteger('cash_out_minor')->default(0);
            $table->unsignedBigInteger('revision')->default(0);
            $table->unsignedBigInteger('counted_minor')->nullable();
            $table->bigInteger('expected_minor')->nullable();
            $table->bigInteger('variance_minor')->nullable();
            $table->dateTime('opened_at');
            $table->dateTime('closed_at')->nullable();
        });
        Schema::create('pos_cash_drawers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('active_session_id')->nullable()->constrained('pos_cash_sessions')->restrictOnDelete();
            $table->boolean('tracking_enabled')->default(false);
        });
        DB::table('pos_cash_drawers')->insert(['id' => 1]);
        Schema::create('pos_cash_movements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('session_id')->constrained('pos_cash_sessions')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->uuid('request_id')->unique();
            $table->string('actor');
            $table->string('kind', 3);
            $table->unsignedBigInteger('amount_minor');
            $table->string('reason', 160);
            $table->dateTime('posted_at');
            $table->index(['session_id', 'id']);
        });
        Schema::table('pos_orders', function (Blueprint $table): void {
            $table->foreignId('cash_session_id')->nullable()->constrained('pos_cash_sessions')->restrictOnDelete();
        });
        Schema::create('pos_refunds', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained('pos_orders')->restrictOnDelete();
            $table->foreignId('cash_session_id')->nullable()->constrained('pos_cash_sessions')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->uuid('request_id')->unique();
            $table->string('fingerprint', 64);
            $table->string('actor');
            $table->string('reason', 160);
            $table->unsignedBigInteger('total_minor');
            $table->json('lines');
            $table->dateTime('posted_at')->index();
            $table->index(['order_id', 'id']);
        });
        Schema::table('pos_stock_movements', function (Blueprint $table): void {
            $table->foreignId('refund_id')->nullable()->constrained('pos_refunds')->restrictOnDelete();
            $table->unique(['refund_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::table('pos_stock_movements', function (Blueprint $table): void {
            $table->dropUnique(['refund_id', 'product_id']);
            $table->dropConstrainedForeignId('refund_id');
        });
        Schema::dropIfExists('pos_refunds');
        Schema::table('pos_orders', fn (Blueprint $table) => $table->dropConstrainedForeignId('cash_session_id'));
        Schema::dropIfExists('pos_cash_movements');
        Schema::dropIfExists('pos_cash_drawers');
        Schema::dropIfExists('pos_cash_sessions');
    }
};
