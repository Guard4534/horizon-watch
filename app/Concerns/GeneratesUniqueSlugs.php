<?php

namespace App\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

trait GeneratesUniqueSlugs
{
    protected const int MAX_SLUG_BASE_LENGTH = 240;

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $scope
     */
    protected static function generateUniqueSlugWithin(Builder $scope, string $name): string
    {
        $defaultSlug = self::slugBase($name);

        $existingSlugs = $scope
            ->where(function (Builder $query) use ($defaultSlug) {
                $query->where('slug', $defaultSlug)
                    ->orWhere('slug', 'like', $defaultSlug.'-%');
            })
            ->pluck('slug');

        $maxSuffix = $existingSlugs
            ->map(function (string $slug) use ($defaultSlug): ?int {
                if ($slug === $defaultSlug) {
                    return 0;
                } elseif (preg_match('/^'.preg_quote($defaultSlug, '/').'-(\d+)$/', $slug, $matches)) {
                    return (int) $matches[1];
                }

                return null;
            })
            ->filter(fn (?int $suffix) => $suffix !== null)
            ->max() ?? 0;

        return $existingSlugs->isEmpty()
            ? $defaultSlug
            : $defaultSlug.'-'.($maxSuffix + 1);
    }

    protected static function slugFallback(): string
    {
        return Str::kebab(class_basename(static::class));
    }

    private static function slugBase(string $name): string
    {
        $slug = rtrim(Str::substr(Str::slug($name), 0, self::MAX_SLUG_BASE_LENGTH), '-');

        return $slug !== '' ? $slug : static::slugFallback();
    }
}
