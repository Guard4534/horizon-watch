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
    private const array TYPES = [
        'prod' => 'production',
        'preprod' => 'preprod',
        'staging' => 'staging',
        'develop' => 'development',
        'demo' => 'demo',
        'testing' => 'testing',
        'worker' => 'staging',
    ];

    /**
     * @var array<string, string>
     */
    private const array COLORS = [
        'production' => 'prod',
        'preprod' => 'preprod',
        'staging' => 'staging',
        'development' => 'develop',
        'demo' => 'demo',
        'testing' => 'testing',
    ];

    public function up(): void
    {
        Schema::table('environments', function (Blueprint $table) {
            $table->string('type')->nullable();
        });

        DB::table('environments')->where('is_production', true)->update(['type' => 'production']);

        foreach (self::TYPES as $color => $type) {
            DB::table('environments')->whereNull('type')->where('color', $color)->update(['type' => $type]);
        }

        DB::table('environments')->whereNull('type')->update(['type' => 'staging']);

        Schema::table('environments', function (Blueprint $table) {
            $table->string('type')->nullable(false)->change();
        });

        Schema::table('environments', function (Blueprint $table) {
            $table->dropColumn(['color', 'is_production']);
        });
    }

    public function down(): void
    {
        Schema::table('environments', function (Blueprint $table) {
            $table->string('color')->nullable();
            $table->boolean('is_production')->default(false);
        });

        foreach (self::COLORS as $type => $color) {
            DB::table('environments')->where('type', $type)->update([
                'color' => $color,
                'is_production' => $type === 'production',
            ]);
        }

        DB::table('environments')->whereNull('color')->update(['color' => 'staging']);

        Schema::table('environments', function (Blueprint $table) {
            $table->string('color')->nullable(false)->change();
        });

        Schema::table('environments', function (Blueprint $table) {
            $table->dropColumn('type');
        });
    }
};
