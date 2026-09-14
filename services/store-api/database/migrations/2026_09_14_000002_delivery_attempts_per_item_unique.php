<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('delivery_attempts', function (Blueprint $table) {
            $table->dropUnique(['order_id', 'attempt_number']);
            $table->unique(['order_item_id', 'attempt_number']);
        });
    }

    public function down(): void
    {
        Schema::table('delivery_attempts', function (Blueprint $table) {
            $table->dropUnique(['order_item_id', 'attempt_number']);
            $table->unique(['order_id', 'attempt_number']);
        });
    }
};
