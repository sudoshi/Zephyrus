<?php

namespace App\Jobs\Middleware;

use App\Exceptions\PatientFlowIngestException;
use App\Integrations\Healthcare\Exceptions\IntegrationProtocolException;
use App\Security\ClinicalPayloads\ClinicalPayloadException;
use Closure;
use Throwable;

/** Converts a queue failure to a stable non-content exception before Laravel
 * serializes it into failed_jobs or reports it to a log/trace exporter.
 */
final class FailClinicalJobSafely
{
    public function handle(object $job, Closure $next): void
    {
        try {
            $next($job);
        } catch (Throwable $exception) {
            $code = match (true) {
                $exception instanceof ClinicalPayloadException => explode(':', $exception->errorCode, 2)[0],
                $exception instanceof IntegrationProtocolException => $exception->errorCode,
                $exception instanceof PatientFlowIngestException => $exception->errorCode,
                default => 'integration_job_failed',
            };

            throw new IntegrationProtocolException($this->safeCode($code));
        }
    }

    private function safeCode(string $code): string
    {
        return preg_match('/^[a-z][a-z0-9_]{2,119}$/', $code) === 1
            ? $code
            : 'integration_job_failed';
    }
}
