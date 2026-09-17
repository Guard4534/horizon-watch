<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // One narrow row per reading, kept for the retention period: about
        // five million rows for thirty environments every fifteen seconds
        // over thirty days. The detail of a reading lives only in
        // environment_states, which keeps the latest one.
        Schema::create('environment_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('environment_id')->constrained()->cascadeOnDelete();
            $table->timestampTz('captured_at');
            $table->string('status');
            $table->string('error')->nullable();
            // AlertRuleMetric values breached by this reading: the open
            // anomalies and their "since" are derived from the run of
            // consecutive snapshots that carry them.
            $table->json('breaches')->default(new Expression("'[]'"));
            $table->unsignedInteger('pending')->default(0);
            $table->unsignedInteger('max_wait_seconds')->default(0);
            $table->unsignedInteger('jobs_per_minute')->default(0);
            $table->unsignedInteger('failed_last_24_hours')->default(0);
            $table->unsignedInteger('workers')->default(0);
            $table->unsignedInteger('node_count')->default(0);
            $table->unsignedInteger('latency_ms')->nullable();

            // Also serves the foreign key: PostgreSQL does not index it.
            $table->index(['environment_id', 'captured_at']);
            // For monitoring:prune, which deletes by age across every
            // environment in chunks: without it each chunk scans the table.
            $table->index('captured_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('environment_snapshots');
    }
};
