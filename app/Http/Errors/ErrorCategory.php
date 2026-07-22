<?php

namespace App\Http\Errors;

/**
 * The one Hummingbird client error taxonomy (plan §4.2).
 *
 * Every error a native or web client must reason about maps to exactly one of
 * these nine categories, so clients branch on a stable, documented set rather
 * than an open-ended list of per-surface leaf codes. Leaf wire codes remain
 * (they carry human copy); {@see ErrorCatalog} classifies each into a category.
 */
enum ErrorCategory: string
{
    case UNAUTHENTICATED = 'unauthenticated';
    case UNAUTHORIZED = 'unauthorized';
    case FORBIDDEN_BY_RELATIONSHIP = 'forbidden_by_relationship';
    case STALE_VERSION = 'stale_version';
    case INVALID_TRANSITION = 'invalid_transition';
    case RATE_LIMITED = 'rate_limited';
    case OFFLINE = 'offline';
    case SERVER_UNAVAILABLE = 'server_unavailable';
    case CONTRACT_MISMATCH = 'contract_mismatch';

    /** The typical HTTP status for the category (OFFLINE is client-only, no status). */
    public function typicalHttpStatus(): ?int
    {
        return match ($this) {
            self::UNAUTHENTICATED => 401,
            self::UNAUTHORIZED, self::FORBIDDEN_BY_RELATIONSHIP => 403,
            self::STALE_VERSION, self::INVALID_TRANSITION => 409,
            self::RATE_LIMITED => 429,
            self::CONTRACT_MISMATCH => 422,
            self::SERVER_UNAVAILABLE => 503,
            self::OFFLINE => null,
        };
    }

    /** Whether an idempotent read may be retried automatically for this category. */
    public function retryableRead(): bool
    {
        return match ($this) {
            self::SERVER_UNAVAILABLE, self::OFFLINE, self::RATE_LIMITED => true,
            default => false,
        };
    }
}
