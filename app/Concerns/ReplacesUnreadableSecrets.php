<?php

namespace App\Concerns;

use Illuminate\Database\Eloquent\Model;

/**
 * @phpstan-require-extends Model
 */
trait ReplacesUnreadableSecrets
{
    /**
     * @param  string  $key
     */
    public function originalIsEquivalent($key): bool
    {
        if ($this->hasCast($key, ['encrypted']) && array_key_exists($key, $this->original)) {
            return ($this->attributes[$key] ?? null) === $this->original[$key];
        }

        return parent::originalIsEquivalent($key);
    }
}
