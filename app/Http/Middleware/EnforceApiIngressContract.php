<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enforce bounded, explicitly typed request bodies on the JSON API boundary.
 * Authentication and authorization remain route responsibilities.
 */
class EnforceApiIngressContract
{
    private const BODY_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

    public function handle(Request $request, Closure $next): Response
    {
        if (! in_array($request->getMethod(), self::BODY_METHODS, true)) {
            return $next($request);
        }

        $declaredLength = $request->headers->get('Content-Length');
        if (is_string($declaredLength) && ctype_digit($declaredLength)
            && (int) $declaredLength > $this->maxBytes($request)) {
            return $this->error(
                $request,
                'payload_too_large',
                'The request body exceeds the allowed size.',
                413,
            );
        }

        $body = (string) $request->getContent();
        if (strlen($body) > $this->maxBytes($request)) {
            return $this->error(
                $request,
                'payload_too_large',
                'The request body exceeds the allowed size.',
                413,
            );
        }

        // Bodyless command routes are valid and have no representation to type.
        if ($body === '') {
            return $next($request);
        }

        if (! $this->supportsContentType($request)) {
            return $this->error(
                $request,
                'unsupported_media_type',
                'This endpoint requires a supported Content-Type.',
                415,
            );
        }

        return $next($request);
    }

    private function supportsContentType(Request $request): bool
    {
        if ($request->is('api/integrations/v1/patient-flow/hl7v2')) {
            $mediaType = strtolower(trim(explode(';', (string) $request->headers->get('Content-Type'), 2)[0]));

            return $request->isJson()
                || in_array($mediaType, [
                    'application/hl7-v2',
                    'application/edi-hl7',
                    'text/plain',
                ], true);
        }

        return $request->isJson();
    }

    private function maxBytes(Request $request): int
    {
        if ($request->is('api/integrations/v1/patient-flow/hl7v2')) {
            return max(1024, (int) config('patient_flow.hl7_ingest_max_bytes', 1_048_576));
        }

        if ($request->is('api/deployment/staffing/imports')) {
            return max(1024, (int) config('ingress.staffing_import_max_bytes', 10_485_760));
        }

        return max(1024, (int) config('ingress.api_max_bytes', 1_048_576));
    }

    private function error(Request $request, string $code, string $message, int $status): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
            'request_id' => $request->attributes->get(AssignRequestIdentity::ATTRIBUTE),
        ], $status)->withHeaders([
            'Cache-Control' => 'private, no-store, max-age=0',
            'Pragma' => 'no-cache',
        ]);
    }
}
