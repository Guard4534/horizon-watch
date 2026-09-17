<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('environment_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('environment_id')->constrained()->cascadeOnDelete();
            $table->timestampTz('captured_at');
            $table->string('status');
            $table->string('error')->nullable();
            $table->json('breaches')->default(new Expression("'[]'"));
            $table->unsignedInteger('pending')->default(0);
            $table->unsignedInteger('max_wait_seconds')->default(0);
            $table->unsignedInteger('jobs_per_minute')->default(0);
            $table->unsignedInteger('failed_in_window')->default(0);
            $table->unsignedInteger('failed_window_minutes')->default(10080);
            $table->unsignedInteger('failed_last_hour')->default(0);
            $table->unsignedInteger('workers')->default(0);
            $table->unsignedInteger('node_count')->default(0);
            $table->unsignedInteger('latency_ms')->nullable();

            $table->index(['environment_id', 'captured_at']);
            $table->index('captured_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('environment_snapshots');
    }
};
