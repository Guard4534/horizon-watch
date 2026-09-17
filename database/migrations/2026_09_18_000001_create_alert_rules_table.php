<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alert_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('scope', 64);
            $table->string('metric');
            $table->decimal('threshold', 12, 2)->nullable();
            $table->string('severity')->nullable();
            $table->boolean('notify_email')->nullable();
            $table->boolean('enabled')->nullable();
            $table->timestamps();

            $table->unique(['team_id', 'scope', 'metric']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alert_rules');
    }
};
