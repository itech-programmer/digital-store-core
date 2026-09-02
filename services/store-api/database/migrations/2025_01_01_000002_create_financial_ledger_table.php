<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('financial_ledger', function (Blueprint $table) {
            $table->id();
            $table->uuid('order_id');
            $table->string('event_type', 32);
            $table->decimal('amount', 12, 2);
            $table->char('currency', 3);
            $table->string('reference_id', 64)->unique();
            $table->timestampTz('created_at')->useCurrent();

            $table->foreign('order_id')->references('id')->on('orders');
            $table->index(['event_type', 'order_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('financial_ledger');
    }
};
