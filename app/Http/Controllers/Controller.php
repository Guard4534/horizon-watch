<?php

namespace App\Http\Controllers;

use Inertia\Inertia;

abstract class Controller
{
    protected function success(string $message): void
    {
        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);
    }
}
