<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('environments', function (Blueprint $table) {
            $table->boolean('polling_enabled')->default(true);
            $table->timestamp('last_polled_at')->nullable();
            $table->timestamp('next_poll_at')->nullable();

            // The scheduler's query every fifteen seconds: enabled and due.
            $table->index(['polling_enabled', 'next_poll_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('environments', function (Blueprint $table) {
            $table->dropIndex(['polling_enabled', 'next_poll_at']);
            $table->dropColumn(['polling_enabled', 'last_polled_at', 'next_poll_at']);
        });
    }
};
