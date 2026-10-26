<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * @var array<string, string>
     */
    private const array RENAMED = [
        'prod' => 'production',
        'develop' => 'development',
        'worker' => 'staging',
    ];

    /**
     * @var list<string>
     */
    private const array TYPES = ['production', 'preprod', 'staging', 'development', 'testing', 'demo'];

    public function up(): void
    {
        Schema::table('alerts', function (Blueprint $table) {
            $table->renameColumn('environment_color', 'environment_type');
        });

        DB::table('alerts')
            ->whereIn('environment_id', DB::table('environments')->where('type', 'production')->select('id'))
            ->update(['environment_type' => 'production']);

        foreach (self::RENAMED as $color => $type) {
            DB::table('alerts')->where('environment_type', $color)->update(['environment_type' => $type]);
        }

        DB::table('alerts')->whereNotIn('environment_type', self::TYPES)->update(['environment_type' => 'staging']);
    }

    public function down(): void
    {
        DB::table('alerts')->where('environment_type', 'production')->update(['environment_type' => 'prod']);
        DB::table('alerts')->where('environment_type', 'development')->update(['environment_type' => 'develop']);

        Schema::table('alerts', function (Blueprint $table) {
            $table->renameColumn('environment_type', 'environment_color');
        });
    }
};
