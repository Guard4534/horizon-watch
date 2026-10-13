<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('environment_states', function (Blueprint $table) {
            $table->string('last_known_status')->nullable();
            $table->timestampTz('last_known_since')->nullable();
            $table->timestampTz('last_known_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('environment_states', function (Blueprint $table) {
            $table->dropColumn(['last_known_status', 'last_known_since', 'last_known_at']);
        });
    }
};
