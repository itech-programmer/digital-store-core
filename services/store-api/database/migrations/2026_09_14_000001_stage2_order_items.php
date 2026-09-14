<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('preferred_supplier', 16)->default('primary')->after('is_active');
        });

        Schema::create('order_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('order_id');
            $table->string('sku', 64);
            $table->unsignedInteger('quantity')->default(1);
            $table->decimal('unit_price', 12, 2);
            $table->char('currency', 3)->default('RUB');
            $table->string('status', 32)->default('pending');
            $table->string('issued_code', 64)->nullable()->unique();
            $table->string('supplier', 16)->nullable();
            $table->unsignedInteger('version')->default(0);
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestampTz('delivered_at')->nullable();
            $table->timestampTz('refunded_at')->nullable();
            $table->timestampsTz();

            $table->foreign('order_id')->references('id')->on('orders')->cascadeOnDelete();
            $table->foreign('sku')->references('sku')->on('products');
            $table->index(['order_id', 'status']);
            $table->index('status');
        });

        Schema::table('product_keys', function (Blueprint $table) {
            $table->uuid('order_item_id')->nullable()->after('order_id');
            $table->foreign('order_item_id')->references('id')->on('order_items')->nullOnDelete();
        });

        Schema::table('delivery_attempts', function (Blueprint $table) {
            $table->uuid('order_item_id')->nullable()->after('order_id');
            $table->foreign('order_item_id')->references('id')->on('order_items')->nullOnDelete();
            $table->index('order_item_id');
        });

        Schema::table('financial_ledger', function (Blueprint $table) {
            $table->uuid('order_item_id')->nullable()->after('order_id');
            $table->foreign('order_item_id')->references('id')->on('order_items')->nullOnDelete();
            $table->index('order_item_id');
        });

        $orders = DB::table('orders')->orderBy('id')->get();
        foreach ($orders as $order) {
            if ($order->sku === null || $order->sku === '') {
                continue;
            }

            $itemId = (string) \Illuminate\Support\Str::uuid();
            DB::table('order_items')->insert([
                'id' => $itemId,
                'order_id' => $order->id,
                'sku' => $order->sku,
                'quantity' => 1,
                'unit_price' => $order->amount,
                'currency' => $order->currency,
                'status' => match ($order->status) {
                    'delivered' => 'delivered',
                    'payment_failed' => 'failed',
                    'out_of_stock', 'delivery_failed' => 'failed',
                    'delivering' => 'delivering',
                    'paid' => 'pending',
                    default => 'pending',
                },
                'issued_code' => $order->issued_code,
                'supplier' => null,
                'version' => 0,
                'position' => 0,
                'delivered_at' => $order->delivered_at,
                'refunded_at' => null,
                'created_at' => $order->created_at ?? now(),
                'updated_at' => $order->updated_at ?? now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('financial_ledger', function (Blueprint $table) {
            $table->dropForeign(['order_item_id']);
            $table->dropColumn('order_item_id');
        });

        Schema::table('delivery_attempts', function (Blueprint $table) {
            $table->dropForeign(['order_item_id']);
            $table->dropColumn('order_item_id');
        });

        Schema::table('product_keys', function (Blueprint $table) {
            $table->dropForeign(['order_item_id']);
            $table->dropColumn('order_item_id');
        });

        Schema::dropIfExists('order_items');

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('preferred_supplier');
        });
    }
};
