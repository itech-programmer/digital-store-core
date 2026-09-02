<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_stock_cache', function (Blueprint $table) {
            $table->string('sku', 64)->primary();
            $table->unsignedInteger('available_count')->default(0);
            $table->timestampTz('updated_at')->useCurrent();

            $table->foreign('sku')->references('sku')->on('products')->cascadeOnDelete();
        });

        DB::statement('CREATE INDEX idx_product_stock_available ON product_stock_cache (available_count DESC) WHERE available_count > 0');

        DB::statement('CREATE INDEX IF NOT EXISTS idx_product_keys_sku_available ON product_keys (sku, id) WHERE status = \'available\'');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS idx_product_keys_sku_available');
        DB::statement('DROP INDEX IF EXISTS idx_product_stock_available');
        Schema::dropIfExists('product_stock_cache');
    }
};
