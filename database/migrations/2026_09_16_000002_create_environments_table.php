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
        Schema::create('environments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            // Unique per organization, not per application: the organization
            // spans several applications and team_id doesn't live on this
            // table, so the generator (not a SQL constraint) enforces it by
            // querying every environment of the organization.
            $table->string('slug');
            $table->string('color');
            $table->string('horizon_url');
            $table->string('basic_auth_user')->nullable();
            $table->text('basic_auth_password')->nullable();
            $table->unsignedSmallInteger('poll_interval_seconds')->default(15);
            $table->timestamp('muted_until')->nullable();
            $table->timestamps();

            $table->unique(['application_id', 'name']);
            $table->index('slug');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('environments');
    }
};
