<?php

namespace App\Data\Pages;

use App\Data\Configuration\ImportPreviewData;
use Spatie\LaravelData\Data;

class ConfigurationImportPageData extends Data
{
    public function __construct(
        public ?ImportPreviewData $preview,
        public ?string $uploadId,
    ) {}
}
