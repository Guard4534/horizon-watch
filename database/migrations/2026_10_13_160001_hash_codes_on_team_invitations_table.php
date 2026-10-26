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
            $table->string('code_hash', 64)->nullable();
        });

        DB::statement("update team_invitations set code_hash = encode(sha256(convert_to(code, 'UTF8')), 'hex')");

        Schema::table('team_invitations', function (Blueprint $table) {
            $table->string('code_hash', 64)->nullable(false)->change();
            $table->unique('code_hash');
            $table->dropColumn('code');
        });
    }

    public function down(): void
    {
        Schema::table('team_invitations', function (Blueprint $table) {
            $table->string('code', 64)->nullable();
        });

        DB::statement('update team_invitations set code = md5(random()::text) || md5(random()::text)');

        Schema::table('team_invitations', function (Blueprint $table) {
            $table->string('code', 64)->nullable(false)->change();
            $table->unique('code');
            $table->dropColumn('code_hash');
        });
    }
};
