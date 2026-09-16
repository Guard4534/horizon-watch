<?php

namespace App\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Same suffixing algorithm as GeneratesUniqueTeamSlugs, generalized to an
 * arbitrary already-scoped query instead of hardcoding withTrashed() on the
 * calling model. Used by models whose slug is unique within a scope other
 * than "the whole table" (Application: per team; Environment: per
 * organization, via a join, not a column on its own table).
 */
trait GeneratesUniqueSlugs
{
    /**
     * Generate a slug from $name, unique within $scope, suffixing -1, -2, …
     * on collision. $scope must already be filtered to the uniqueness
     * boundary (e.g. ->where('team_id', $teamId)).
     *
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
