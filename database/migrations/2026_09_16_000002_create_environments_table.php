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
            // Denormalized from the application so the uniqueness scope is
            // on the row: the slug is unique per organization, not per
            // application (two applications can each have a "production",
            // and the URL is "/{team}/environments/{slug}"), and a unique
            // index cannot reach through a join. Applications never move
            // between organizations — nothing writes applications.team_id
            // after creation — so this cannot drift; Environment's creating
            // hook fills it from the application.
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->string('color');
            $table->string('horizon_url');
            $table->string('basic_auth_user')->nullable();
            $table->text('basic_auth_password')->nullable();
            $table->unsignedSmallInteger('poll_interval_seconds')->default(15);
            $table->timestamp('muted_until')->nullable();
            $table->timestamps();

            $table->unique(['application_id', 'name']);
            // The rule Environment::generateUniqueSlug() computes, now also
            // held by the database. The generator is a read-then-write with
            // no lock, so two admins creating environments at the same time
            // could both settle on the same slug (applications "acme" with
            // "shop-prod" and "acme-shop" with "prod" both give
            // "acme-shop-prod"); the duplicate then reached
            // keyBy('slug') in the repository, which silently dropped one
            // row — an environment that exists, is granted, and never
            // appears on the wall. A rejected insert is a loud, retryable
            // failure instead.
            //
            // This replaces the plain index on slug: every lookup is
            // already scoped to one organization (Team::environments() and
            // the scoped route bindings), so the composite serves them.
            $table->unique(['team_id', 'slug']);
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
