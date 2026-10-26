<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('environment_states', function (Blueprint $table) {
            $table->json('breach_runs')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('environment_states', function (Blueprint $table) {
            $table->dropColumn('breach_runs');
        });
    }
};
