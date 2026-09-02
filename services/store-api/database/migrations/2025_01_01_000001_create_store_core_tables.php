<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('sku', 64)->unique();
            $table->string('name');
            $table->string('type', 32);
            $table->decimal('price', 12, 2);
            $table->char('currency', 3)->default('RUB');
            $table->string('image_path')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('orders', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('public_id', 32)->unique();
            $table->string('sku', 64);
            $table->decimal('amount', 12, 2);
            $table->char('currency', 3)->default('RUB');
            $table->string('status', 32)->default('created');
            $table->string('issued_code', 64)->nullable()->unique();
            $table->unsignedInteger('version')->default(0);
            $table->timestampTz('paid_at')->nullable();
            $table->timestampTz('delivered_at')->nullable();
            $table->timestampsTz();

            $table->foreign('sku')->references('sku')->on('products');
            $table->index('status');
        });

        Schema::create('product_keys', function (Blueprint $table) {
            $table->id();
            $table->string('sku', 64);
            $table->string('code', 64)->unique();
            $table->string('status', 16)->default('available');
            $table->uuid('order_id')->nullable();
            $table->timestampTz('reserved_at')->nullable();
            $table->timestampTz('delivered_at')->nullable();
            $table->timestampsTz();

            $table->foreign('sku')->references('sku')->on('products');
            $table->foreign('order_id')->references('id')->on('orders')->nullOnDelete();
            $table->index(['sku', 'status']);
        });

        Schema::create('processed_webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('event_id', 64)->unique();
            $table->uuid('order_id');
            $table->string('status', 16);
            $table->decimal('amount', 12, 2);
            $table->char('currency', 3);
            $table->json('payload');
            $table->timestampTz('processed_at')->useCurrent();

            $table->foreign('order_id')->references('id')->on('orders');
        });

        Schema::create('pending_webhooks', function (Blueprint $table) {
            $table->string('event_id', 64)->primary();
            $table->string('order_public_id', 32);
            $table->string('status', 16);
            $table->decimal('amount', 12, 2);
            $table->char('currency', 3);
            $table->json('payload');
            $table->timestampTz('received_at')->useCurrent();

            $table->index('order_public_id');
        });

        Schema::create('delivery_attempts', function (Blueprint $table) {
            $table->id();
            $table->uuid('order_id');
            $table->string('request_id', 64)->unique();
            $table->string('supplier', 16);
            $table->unsignedSmallInteger('attempt_number');
            $table->string('status', 24);
            $table->string('code', 64)->nullable();
            $table->string('error_reason', 64)->nullable();
            $table->timestampTz('started_at')->useCurrent();
            $table->timestampTz('finished_at')->nullable();

            $table->foreign('order_id')->references('id')->on('orders');
            $table->unique(['order_id', 'attempt_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_attempts');
        Schema::dropIfExists('pending_webhooks');
        Schema::dropIfExists('processed_webhook_events');
        Schema::dropIfExists('product_keys');
        Schema::dropIfExists('orders');
        Schema::dropIfExists('products');
    }
};
