<?php

namespace App\Http\Middleware;

use App\Services\Patient\PatientResponseDecorator;
use Closure;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/** Apply patient-safe cache and indexing controls to successes and errors. */
class ProtectPatientResponse
{
    public function __construct(
        private readonly PatientResponseDecorator $decorator,
        private readonly ExceptionHandler $exceptions,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        try {
            $response = $next($request);
        } catch (Throwable $exception) {
            $this->exceptions->report($exception);
            $response = $this->exceptions->render($request, $exception);
        }

        return $this->decorator->decorate($response, $request);
    }
}
