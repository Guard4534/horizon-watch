<?php

namespace App\Configuration;

use RuntimeException;

final class ImportBlocked extends RuntimeException
{
    public function __construct()
    {
        parent::__construct(__('Fix the invalid items in the file and upload it again.'));
    }
}
