<?php

namespace App\Configuration;

interface FormatUpgrade
{
    /**
     * Turns a document of version N into one of version N + 1; the reader sets `version`.
     *
     * @param  array<array-key, mixed>  $document
     * @return array<array-key, mixed>
     */
    public function upgrade(array $document): array;
}
