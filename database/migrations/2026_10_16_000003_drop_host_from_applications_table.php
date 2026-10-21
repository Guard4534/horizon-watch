<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->dropColumn('host');
        });
    }

    public function down(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->string('host')->nullable();
        });

        foreach (DB::table('applications')->select('id')->lazyById() as $application) {
            $url = DB::table('environments')
                ->where('application_id', $application->id)
                ->orderByRaw("case when type = 'production' then 0 else 1 end")
                ->orderBy('id')
                ->value('horizon_url');

            DB::table('applications')->where('id', $application->id)->update([
                'host' => is_string($url) ? strtolower((string) parse_url($url, PHP_URL_HOST)) : '',
            ]);
        }

        Schema::table('applications', function (Blueprint $table) {
            $table->string('host')->nullable(false)->change();
        });
    }
};
