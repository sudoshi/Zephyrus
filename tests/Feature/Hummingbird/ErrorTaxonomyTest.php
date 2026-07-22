<?php

namespace Tests\Feature\Hummingbird;

use App\Http\Errors\ErrorCatalog;
use App\Http\Errors\ErrorCategory;
use PHPUnit\Framework\TestCase;

/**
 * Guards the one client error taxonomy (plan §4.2): the nine categories are
 * stable, and every patient/staff-communication wire code is classified.
 */
class ErrorTaxonomyTest extends TestCase
{
    public function test_taxonomy_has_exactly_the_nine_named_categories(): void
    {
        $values = array_map(fn (ErrorCategory $c): string => $c->value, ErrorCategory::cases());
        sort($values);

        $this->assertSame([
            'contract_mismatch',
            'forbidden_by_relationship',
            'invalid_transition',
            'offline',
            'rate_limited',
            'server_unavailable',
            'stale_version',
            'unauthenticated',
            'unauthorized',
        ], $values);
    }

    public function test_every_catalog_code_maps_to_a_valid_category_and_status(): void
    {
        foreach (ErrorCatalog::all() as $code => $category) {
            $this->assertInstanceOf(ErrorCategory::class, $category, "{$code} must map to a category.");
            // Every classified server code has a concrete HTTP status
            // (OFFLINE is a client-only category and is not a server wire code).
            $this->assertNotNull(
                $category->typicalHttpStatus(),
                "{$code} maps to OFFLINE, which is client-only and cannot be a server wire code.",
            );
        }
    }

    public function test_patient_and_communication_wire_codes_are_all_classified(): void
    {
        // The mature closed-set surfaces: the patient response decorator's allowed
        // codes and its status fallbacks, plus the staff communication failures.
        $required = [
            'account_inactive', 'account_locked', 'invalid_credentials',
            'invalid_enrollment_challenge', 'invalid_refresh_token', 'idempotency_conflict',
            'messaging_unavailable', 'not_found', 'patient_realm_required',
            'stale_thread_version', 'thread_closed', 'thread_message_limit_reached',
            'urgent_guidance_changed', 'validation_failed',
            // status fallbacks
            'unauthenticated', 'forbidden', 'conflict', 'payload_too_large',
            'unsupported_media_type', 'rate_limited', 'service_unavailable', 'request_failed',
            // staff communication failures
            'communications_unavailable', 'stale_version', 'already_assigned', 'response_required',
        ];

        foreach ($required as $code) {
            $this->assertNotNull(
                ErrorCatalog::categoryFor($code),
                "Wire code '{$code}' is unclassified; add it to the ErrorCatalog taxonomy.",
            );
        }
    }
}
