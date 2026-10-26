<?php

namespace App\Listeners\Health;

use Illuminate\Foundation\Events\DiagnosingHealth;
use Illuminate\Support\Facades\DB;

class CheckDatabase
{
    public function handle(DiagnosingHealth $event): void
    {
        DB::select('select 1');
    }
}
