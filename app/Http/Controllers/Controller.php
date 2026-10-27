<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

/**
 * Base controller. AuthorizesRequests gives every controller $this->authorize(),
 * which runs the matching policy (for example UserPolicy::approve).
 */
abstract class Controller
{
    use AuthorizesRequests;
}
