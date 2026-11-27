<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_customers', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 120);
            $table->string('email', 254)->nullable();
            $table->string('phone', 40)->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['deleted_at', 'name', 'id']);
        });

        Schema::table('pos_orders', function (Blueprint $table): void {
            $table->foreignId('customer_id')->nullable()->constrained('pos_customers')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('pos_orders', fn (Blueprint $table) => $table->dropConstrainedForeignId('customer_id'));
        Schema::dropIfExists('pos_customers');
    }
};
