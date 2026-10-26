<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_instance_admin')->default(false);
        });

        $oldest = DB::table('users')->orderBy('created_at')->orderBy('id')->value('id');

        if ($oldest !== null) {
            DB::table('users')->where('id', $oldest)->update(['is_instance_admin' => true]);
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_instance_admin');
        });
    }
};
