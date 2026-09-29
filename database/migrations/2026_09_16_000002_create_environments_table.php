<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('environments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained()->cascadeOnDelete();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->string('color');
            $table->string('horizon_url');
            $table->string('basic_auth_user')->nullable();
            $table->text('basic_auth_password')->nullable();
            $table->unsignedSmallInteger('poll_interval_seconds');
            $table->timestamps();

            $table->unique(['application_id', 'name']);
            $table->unique(['team_id', 'slug']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('environments');
    }
};
