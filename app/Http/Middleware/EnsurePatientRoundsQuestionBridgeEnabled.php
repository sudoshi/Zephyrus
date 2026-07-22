<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The staff-side promotion bridge is independent of both Virtual Rounds and
 * patient message composition. Hiding it while disabled prevents a staff
 * feature toggle from accidentally making patient content bridgeable.
 */
class EnsurePatientRoundsQuestionBridgeEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless((bool) config('rounds.patient_question_bridge_enabled'), 404);

        return $next($request);
    }
}
