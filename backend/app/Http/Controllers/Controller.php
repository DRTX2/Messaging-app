<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Validation\ValidatesRequests;

abstract class Controller
{
    use AuthorizesRequests, ValidatesRequests;

    protected const MAX_PER_PAGE = 50;

    /** Clamp ?per_page to [1, MAX_PER_PAGE]. */
    protected function perPage(\Illuminate\Http\Request $request, int $default = 20): int
    {
        return max(1, min((int) $request->input('per_page', $default), self::MAX_PER_PAGE));
    }
}
