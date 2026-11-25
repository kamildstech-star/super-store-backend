<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_orders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->uuid('request_id');
            $table->string('cashier', 255);
            $table->string('customer', 120)->nullable();
            $table->string('note', 160)->nullable();
            $table->unsignedSmallInteger('line_count');
            $table->unsignedBigInteger('total_minor');
            $table->json('lines');
            $table->timestamps();
            $table->unique(['user_id', 'request_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_orders');
    }
};
