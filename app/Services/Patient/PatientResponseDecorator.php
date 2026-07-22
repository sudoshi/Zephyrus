<?php

namespace App\Services\Patient;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PatientResponseDecorator
{
    public function __construct(private readonly PatientResponseMetadata $metadata) {}

    public function decorate(Response $response, Request $request): Response
    {
        if ($response instanceof JsonResponse) {
            $payload = (array) $response->getData(true);

            if ($response->getStatusCode() >= 400) {
                $payload = $this->patientSafeErrorPayload($payload, $response->getStatusCode());
            }

            $payload['data'] ??= null;
            $payload['meta'] = $this->metadata->forRequest(
                $request,
                is_array($payload['meta'] ?? null) ? $payload['meta'] : [],
            );
            $payload['links'] = empty($payload['links'] ?? null)
                ? (object) []
                : $payload['links'];
            $response->setData($payload);
        }

        $response->headers->set('Cache-Control', 'private, no-store, max-age=0');
        $response->headers->set('Pragma', 'no-cache');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow, noarchive');
        $response->headers->set('Vary', 'Authorization', false);

        return $response;
    }

    /**
     * Patient clients receive one stable, non-diagnostic failure contract.
     * Framework exception messages are deliberately discarded because they
     * can contain implementation details or request-derived clinical values.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function patientSafeErrorPayload(array $payload, int $status): array
    {
        $fieldErrors = is_array($payload['errors'] ?? null) ? $payload['errors'] : null;
        $incomingCode = is_array($payload['error'] ?? null)
            ? ($payload['error']['code'] ?? null)
            : null;

        $code = $fieldErrors !== null
            ? 'validation_failed'
            : $this->safeErrorCode(is_string($incomingCode) ? $incomingCode : null, $status);

        $safe = [
            'data' => null,
            'error' => [
                'code' => $code,
                'message' => $this->safeErrorMessage($code),
            ],
        ];

        if ($fieldErrors !== null) {
            $safe['errors'] = $fieldErrors;
        }

        if (is_array($payload['meta'] ?? null)) {
            $safe['meta'] = $payload['meta'];
        }

        if (array_key_exists('links', $payload)) {
            $safe['links'] = $payload['links'];
        }

        return $safe;
    }

    private function safeErrorCode(?string $incomingCode, int $status): string
    {
        $allowed = [
            'account_inactive',
            'account_locked',
            'invalid_credentials',
            'invalid_enrollment_challenge',
            'invalid_refresh_token',
            'idempotency_conflict',
            'messaging_unavailable',
            'message_not_amendable',
            'not_found',
            'patient_realm_required',
            'stale_thread_version',
            'thread_closed',
            'thread_message_limit_reached',
            'urgent_guidance_changed',
            'validation_failed',
        ];

        if ($incomingCode !== null && in_array($incomingCode, $allowed, true)) {
            return $incomingCode;
        }

        return match ($status) {
            401 => 'unauthenticated',
            403 => 'forbidden',
            404 => 'not_found',
            409 => 'conflict',
            413 => 'payload_too_large',
            415 => 'unsupported_media_type',
            422 => 'validation_failed',
            429 => 'rate_limited',
            default => $status >= 500 ? 'service_unavailable' : 'request_failed',
        };
    }

    private function safeErrorMessage(string $code): string
    {
        return match ($code) {
            'account_inactive' => 'This patient account is not active.',
            'account_locked' => 'This patient account is temporarily unavailable.',
            'invalid_credentials' => 'The patient credentials could not be verified.',
            'invalid_enrollment_challenge' => 'The enrollment challenge is invalid or no longer available.',
            'invalid_refresh_token' => 'A valid patient refresh token is required.',
            'idempotency_conflict' => 'This request key was already used for different content.',
            'messaging_unavailable' => 'Patient messaging is not available right now.',
            'message_not_amendable' => 'This message already has a correction or withdrawal. Refresh the conversation to review it.',
            'not_found' => 'The requested resource was not found.',
            'patient_realm_required' => 'A valid patient credential is required.',
            'stale_thread_version' => 'This conversation changed. Refresh it before trying again.',
            'thread_closed' => 'This conversation is closed and cannot receive another message.',
            'thread_message_limit_reached' => 'This conversation reached its message limit. Start a new conversation to continue.',
            'urgent_guidance_changed' => 'The immediate-help guidance changed. Review it before sending.',
            'unauthenticated' => 'Authentication is required.',
            'forbidden' => 'This patient credential cannot perform the requested action.',
            'conflict' => 'The request conflicts with the current patient account state.',
            'payload_too_large' => 'The request body exceeds the allowed size.',
            'unsupported_media_type' => 'This endpoint requires a supported content type.',
            'validation_failed' => 'The submitted patient request is invalid.',
            'rate_limited' => 'Too many requests were submitted. Please try again later.',
            'service_unavailable' => 'The patient service is temporarily unavailable.',
            default => 'The patient request could not be completed.',
        };
    }
}
