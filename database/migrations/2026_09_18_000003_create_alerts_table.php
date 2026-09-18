<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alerts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('environment_id')->nullable()->constrained()->nullOnDelete();
            $table->string('metric');
            $table->string('severity');
            $table->string('application_name');
            $table->string('environment_name');
            $table->string('environment_color');
            $table->decimal('threshold', 12, 2);
            $table->string('unit', 8);
            $table->decimal('value', 14, 2)->nullable();
            $table->json('detail')->default('{}');
            $table->timestampTz('opened_at');
            $table->timestampTz('last_seen_at');
            $table->timestampTz('resolved_at')->nullable();
            $table->timestampTz('muted_until')->nullable();
            $table->boolean('muted_indefinitely')->default(false);
            $table->foreignId('muted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('handled_at')->nullable();
            $table->foreignId('handled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('last_notified_at')->nullable();
            $table->boolean('notified')->default(false);
            $table->timestampTz('digested_at')->nullable();
            $table->timestampTz('resolution_notified_at')->nullable();
            $table->timestamps();

            $table->index(['team_id', 'resolved_at', 'opened_at']);
        });

        DB::statement('create unique index alerts_open_unique on alerts (environment_id, metric) where resolved_at is null');
    }

    public function down(): void
    {
        Schema::dropIfExists('alerts');
    }
};
