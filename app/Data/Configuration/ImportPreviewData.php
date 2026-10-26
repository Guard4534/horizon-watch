<?php

namespace App\Data\Configuration;

use Spatie\LaravelData\Data;

class ImportPreviewData extends Data
{
    public function __construct(
        public string $fileName,
        public ?string $organization,
        public ?string $exportedAt,
        /** @var array<int, ImportItemData> */
        public array $items,
        public bool $blocked,
    ) {}
}
