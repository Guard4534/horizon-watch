<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
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

    public function down(): void
    {
        Schema::dropIfExists('environment_states');
    }
};
