<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_issuances', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('request_id', 64)->unique();
            $table->string('supplier', 16);
            $table->uuid('order_item_id');
            $table->string('sku', 64);
            $table->string('code', 64)->nullable();
            $table->string('status', 32);
            $table->json('raw_response')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->foreign('order_item_id')->references('id')->on('order_items')->cascadeOnDelete();
            $table->foreign('sku')->references('sku')->on('products');
            $table->index(['order_item_id', 'status']);
        });

        DB::statement('CREATE UNIQUE INDEX supplier_issuances_code_unique ON supplier_issuances (code) WHERE code IS NOT NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_issuances');
    }
};
