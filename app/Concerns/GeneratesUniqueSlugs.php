<?php

namespace App\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

trait GeneratesUniqueSlugs
{
    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $scope
     */
    protected static function generateUniqueSlugWithin(Builder $scope, string $name, ?int $excludeId = null): string
    {
        $defaultSlug = Str::slug($name);

        $scope->where(function (Builder $query) use ($defaultSlug) {
            $query->where('slug', $defaultSlug)
                ->orWhere('slug', 'like', $defaultSlug.'-%');
        });

        if ($excludeId) {
            $scope->where($scope->getModel()->getQualifiedKeyName(), '!=', $excludeId);
        }

        $existingSlugs = $scope->pluck('slug');

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
}
