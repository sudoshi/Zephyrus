<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Feature gate for the Home Hospital module (HOME_HOSPITAL_ENABLED).
 * A 404 (not 403) keeps the module invisible when off; disabling the flag
 * stops new work without deleting existing episode or audit records.
 */
class EnsureHomeHospitalEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless((bool) config('home_hospital.enabled'), 404);

        return $next($request);
    }
}
