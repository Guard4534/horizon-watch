<?php

namespace App\Enums;

enum ImportItemStatus: string
{
    case New = 'new';
    case Changed = 'changed';
    case Unchanged = 'unchanged';
    case Invalid = 'invalid';
}
