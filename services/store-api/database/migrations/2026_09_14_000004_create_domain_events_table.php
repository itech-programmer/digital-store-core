<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('domain_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('aggregate_type', 64);
            $table->uuid('aggregate_id');
            $table->string('event_type', 64);
            $table->jsonb('payload');
            $table->timestampTz('created_at');

            $table->index(['aggregate_type', 'aggregate_id', 'created_at'], 'domain_events_aggregate_time_idx');
            $table->index(['event_type', 'created_at'], 'domain_events_type_time_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('domain_events');
    }
};
