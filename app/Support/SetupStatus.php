<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Cache;

class SetupStatus
{
    private const CACHE_KEY = 'setup.completed';

    /**
     * Only a positive answer is cached: a panel that has been set up never goes
     * back, while an empty one must see its first account immediately.
     */
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
