<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('team_invitations', function (Blueprint $table) {
            $table->dropForeign(['invited_by']);
        });

        Schema::table('team_invitations', function (Blueprint $table) {
            $table->unsignedBigInteger('invited_by')->nullable()->change();
            $table->foreign('invited_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        DB::table('team_invitations')->whereNull('invited_by')->delete();

        Schema::table('team_invitations', function (Blueprint $table) {
            $table->dropForeign(['invited_by']);
        });

        Schema::table('team_invitations', function (Blueprint $table) {
            $table->unsignedBigInteger('invited_by')->nullable(false)->change();
            $table->foreign('invited_by')->references('id')->on('users')->cascadeOnDelete();
        });
    }
};
