<?php

namespace App\Data\Pages;

use Spatie\LaravelData\Data;

/**
 * The mobile profile. Name, email, locale and the organizations with the
 * viewer's role already travel as shared props: this page adds only what
 * the current organization says about itself.
 */
class MePageData extends Data
{
    public function __construct(
        public int $memberCount,
    ) {}
}
