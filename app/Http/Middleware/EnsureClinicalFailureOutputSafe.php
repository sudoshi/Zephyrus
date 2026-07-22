<?php

namespace App\Http\Middleware;

use App\Security\ClinicalPayloads\ClinicalContentGuard;
use App\Services\Patient\PatientResponseDecorator;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureClinicalFailureOutputSafe
{
    public function __construct(private readonly ClinicalContentGuard $guard) {}

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        if (! $response instanceof JsonResponse || $response->getStatusCode() < 400) {
            return $response;
        }

        $data = $response->getData(true);
        $safeValidation = $this->safeValidationResponse($request, $response->getStatusCode(), $data);
        if ($safeValidation !== null) {
            return $safeValidation;
        }
        if (! $this->guard->contains($this->diagnosticFields($data))) {
            return $response;
        }

        return response()->json([
            'error' => [
                'code' => 'clinical_failure_output_suppressed',
                'message' => 'The operation failed and its unsafe diagnostic output was suppressed.',
            ],
        ], 500)->withHeaders([
            'Cache-Control' => 'private, no-store, max-age=0',
            'Pragma' => 'no-cache',
        ]);
    }

    /**
     * Inspect diagnostic output, not an authorized domain projection that a
     * conflict response may carry for optimistic-concurrency recovery.
     *
     * @return array<string, mixed>
     */
    private function diagnosticFields(mixed $data): array
    {
        if (! is_array($data)) {
            return ['message' => $data];
        }

        return array_intersect_key($data, array_flip([
            'error',
            'errors',
            'message',
            'exception',
            'trace',
            'debug',
        ]));
    }

    private function safeValidationResponse(Request $request, int $status, mixed $data): ?JsonResponse
    {
        if ($status !== 422
            || ! is_array($data)
            || ! is_array($data['errors'] ?? null)
            || $data['errors'] === []) {
            return null;
        }

        $errors = [];
        foreach ($data['errors'] as $field => $messages) {
            if (! is_string($field)
                || preg_match('/^[A-Za-z0-9_.-]{1,190}$/', $field) !== 1
                || ! is_array($messages)
                || $messages === []
                || ! array_is_list($messages)) {
                return null;
            }
            $errors[$field] = ['The submitted value is invalid.'];
        }

        $payload = $request->is('api/patient/v1', 'api/patient/v1/*')
            ? [
                'data' => null,
                'error' => [
                    'code' => 'validation_failed',
                    'message' => 'The submitted patient request is invalid.',
                ],
                'errors' => $errors,
                'meta' => is_array($data['meta'] ?? null) ? $data['meta'] : [],
                'links' => empty($data['links'] ?? null) ? (object) [] : $data['links'],
            ]
            : [
                'message' => 'The submitted data failed validation.',
                'errors' => $errors,
            ];

        $response = response()->json($payload, 422)->withHeaders([
            'Cache-Control' => 'private, no-store, max-age=0',
            'Pragma' => 'no-cache',
        ]);

        if ($request->is('api/patient/v1', 'api/patient/v1/*')) {
            return app(PatientResponseDecorator::class)->decorate($response, $request);
        }

        return $response;
    }
}
