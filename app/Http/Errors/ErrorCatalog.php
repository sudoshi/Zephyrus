<?php

namespace App\Http\Errors;

/**
 * Classifies each patient/communication wire error code into one
 * {@see ErrorCategory}. Additive: leaf codes and their human copy are unchanged;
 * this is the mapping clients use to branch on the one taxonomy. The patient and
 * staff-communication surfaces expose a closed set and are fully covered here;
 * broader staff-mobile surfaces adopt the catalog incrementally.
 */
class ErrorCatalog
{
    /** @var array<string, ErrorCategory> */
    private const MAP = [
        // Authentication / identity.
        'unauthenticated' => ErrorCategory::UNAUTHENTICATED,
        'account_inactive' => ErrorCategory::UNAUTHENTICATED,
        'account_locked' => ErrorCategory::UNAUTHENTICATED,
        'invalid_credentials' => ErrorCategory::UNAUTHENTICATED,
        'invalid_enrollment_challenge' => ErrorCategory::UNAUTHENTICATED,
        'invalid_refresh_token' => ErrorCategory::UNAUTHENTICATED,

        // Authorization / realm / relationship.
        'patient_realm_required' => ErrorCategory::UNAUTHORIZED,
        'forbidden' => ErrorCategory::FORBIDDEN_BY_RELATIONSHIP,
        // Patient 404s are the IDOR-safe non-disclosure of a resource this
        // relationship may not see; they are a relationship denial, not a bug.
        'not_found' => ErrorCategory::FORBIDDEN_BY_RELATIONSHIP,

        // Optimistic concurrency / freshness.
        'stale_version' => ErrorCategory::STALE_VERSION,
        'stale_thread_version' => ErrorCategory::STALE_VERSION,
        'urgent_guidance_changed' => ErrorCategory::STALE_VERSION,

        // Illegal state transition.
        'conflict' => ErrorCategory::INVALID_TRANSITION,
        'thread_closed' => ErrorCategory::INVALID_TRANSITION,
        'already_assigned' => ErrorCategory::INVALID_TRANSITION,
        'response_required' => ErrorCategory::INVALID_TRANSITION,

        // Throughput limits.
        'rate_limited' => ErrorCategory::RATE_LIMITED,
        'thread_message_limit_reached' => ErrorCategory::RATE_LIMITED,

        // Server / dependency unavailable.
        'service_unavailable' => ErrorCategory::SERVER_UNAVAILABLE,
        'messaging_unavailable' => ErrorCategory::SERVER_UNAVAILABLE,
        'communications_unavailable' => ErrorCategory::SERVER_UNAVAILABLE,
        'request_failed' => ErrorCategory::SERVER_UNAVAILABLE,

        // Request/contract shape.
        'validation_failed' => ErrorCategory::CONTRACT_MISMATCH,
        'idempotency_conflict' => ErrorCategory::CONTRACT_MISMATCH,
        'idempotency_key_required' => ErrorCategory::CONTRACT_MISMATCH,
        'payload_too_large' => ErrorCategory::CONTRACT_MISMATCH,
        'unsupported_media_type' => ErrorCategory::CONTRACT_MISMATCH,
    ];

    public static function categoryFor(string $code): ?ErrorCategory
    {
        return self::MAP[$code] ?? null;
    }

    /** @return array<string, ErrorCategory> */
    public static function all(): array
    {
        return self::MAP;
    }

    /** @return list<string> */
    public static function codes(): array
    {
        return array_keys(self::MAP);
    }
}
