<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The environments an inviter picked for a "manual" visibility invite
     * have nowhere to live until the invitation is accepted (only then does
     * a user_id exist to write into environment_user). This pivot is that
     * holding place; AcceptInvitation copies its rows into environment_user
     * and the invitation is done with it either way.
     */
    public function up(): void
    {
        Schema::create('environment_team_invitation', function (Blueprint $table) {
            $table->id();
            $table->foreignId('environment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('team_invitation_id')->constrained()->cascadeOnDelete();

            $table->unique(['environment_id', 'team_invitation_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('environment_team_invitation');
    }
};
