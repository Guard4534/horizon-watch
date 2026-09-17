<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('environment_team_invitation', function (Blueprint $table) {
            $table->id();
            $table->foreignId('environment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('team_invitation_id')->constrained()->cascadeOnDelete();

            $table->unique(['environment_id', 'team_invitation_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('environment_team_invitation');
    }
};
