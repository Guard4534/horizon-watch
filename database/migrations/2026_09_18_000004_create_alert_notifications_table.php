<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alert_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('alert_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('kind');
            $table->string('channel');
            $table->string('target', 255);
            $table->string('status');
            $table->string('error', 64)->nullable();
            $table->timestampTz('sent_at');
            $table->unsignedSmallInteger('environment_count')->nullable();
            $table->uuid('delivery_id')->nullable()->unique();

            $table->index(['team_id', 'sent_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alert_notifications');
    }
};
