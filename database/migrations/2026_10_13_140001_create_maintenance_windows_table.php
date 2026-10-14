<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('maintenance_windows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('scope', 16);
            $table->foreignId('application_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('environment_id')->nullable()->constrained()->cascadeOnDelete();
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at');
            $table->string('reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['team_id', 'ends_at']);
            $table->index('application_id');
            $table->index('environment_id');
            $table->index('ends_at');
        });

        DB::statement('alter table maintenance_windows add constraint maintenance_windows_ends_after_start check (ends_at > starts_at)');
        DB::statement("alter table maintenance_windows add constraint maintenance_windows_scope_target check (
            (scope = 'organization' and application_id is null and environment_id is null)
            or (scope = 'application' and application_id is not null and environment_id is null)
            or (scope = 'environment' and application_id is null and environment_id is not null)
        )");
        DB::statement('create index maintenance_windows_created_by_index on maintenance_windows (created_by) where created_by is not null');
    }

    public function down(): void
    {
        Schema::dropIfExists('maintenance_windows');
    }
};
