<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $ids = [];

        foreach (DB::table('environments')->select(['id', 'name'])->lazyById() as $environment) {
            if (preg_match('/^(production|prod|prd|live)([-_.\s].*)?$/i', trim((string) $environment->name)) === 1) {
                $ids[] = $environment->id;
            }
        }

        foreach (array_chunk($ids, 500) as $chunk) {
            DB::table('environments')->whereIn('id', $chunk)->update(['is_production' => true]);
        }
    }

    public function down(): void {}
};
