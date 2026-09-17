<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // One row per environment, replaced by every reading. The JSON
        // shapes are a contract between the poller that writes them and
        // StoredReadings that reads them:
        //   nodes:        [{hostname, status: running|paused, workers, supervisors, queues,
        //                   seenAt: ISO-8601 of the last successful reading that listed it}]
        //   queues:       [{name, supervisor: string|null, workers, pending, waitSeconds, runtimeSeconds: float|null}]
        //   failed_jobs:  [{job, queue, exception, tries, failedAt: ISO-8601}]
        //   pending_jobs: [{job, queue, reservedAt: ISO-8601}]  (reserved jobs only)
        Schema::create('environment_states', function (Blueprint $table) {
            $table->id();
            $table->foreignId('environment_id')->unique()->constrained()->cascadeOnDelete();
            $table->timestampTz('captured_at');
            $table->string('status');
            $table->string('error')->nullable();
            $table->string('horizon_status')->nullable();
            $table->json('nodes');
            $table->json('queues');
            $table->json('failed_jobs');
            $table->json('pending_jobs');
            $table->unsignedInteger('latency_ms')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('environment_states');
    }
};
