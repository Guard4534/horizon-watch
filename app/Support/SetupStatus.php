<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Cache;

class SetupStatus
{
    private const CACHE_KEY = 'setup.completed';

    public static function isComplete(): bool
    {
        if (Cache::get(self::CACHE_KEY) === true) {
            return true;
        }

        $complete = User::query()->exists();

        if ($complete) {
            Cache::forever(self::CACHE_KEY, true);
        }

        return $complete;
    }
}
