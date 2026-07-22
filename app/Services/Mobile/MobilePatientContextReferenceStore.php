<?php

namespace App\Services\Mobile;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * Issues and resolves short-lived, opaque staff-mobile patient context handles.
 *
 * The unique patient_context_ref index is the lookup boundary: resolving a
 * handle never enumerates clinical tables. The mapping can be expired or
 * revoked without changing a source-system patient reference.
 */
class MobilePatientContextReferenceStore
{
    private const TABLE = 'ops.patient_operational_context_cache';

    public function issue(string $patientRef): string
    {
        if (trim($patientRef) === '') {
            throw new RuntimeException('A patient context handle requires a non-empty internal reference.');
        }

        $contextRef = 'ptok_'.substr(hash_hmac('sha256', $patientRef, $this->signingKey()), 0, 24);

        if (! Schema::hasTable(self::TABLE)) {
            throw new RuntimeException('The mobile patient context reference store is not available.');
        }

        $now = now();
        DB::table(self::TABLE)->updateOrInsert(
            ['patient_context_ref' => $contextRef],
            [
                'patient_ref' => $patientRef,
                'generated_at' => $now,
                'expires_at' => $now->copy()->addMinutes($this->ttlMinutes()),
                'updated_at' => $now,
                'created_at' => $now,
            ],
        );

        return $contextRef;
    }

    public function resolve(string $contextRef): ?string
    {
        if (! $this->isOpaque($contextRef) || ! Schema::hasTable(self::TABLE)) {
            return null;
        }

        $patientRef = DB::table(self::TABLE)
            ->where('patient_context_ref', $contextRef)
            ->where(function ($query): void {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->value('patient_ref');

        return is_string($patientRef) && trim($patientRef) !== '' ? $patientRef : null;
    }

    public function revoke(string $contextRef): bool
    {
        if (! $this->isOpaque($contextRef) || ! Schema::hasTable(self::TABLE)) {
            return false;
        }

        return DB::table(self::TABLE)
            ->where('patient_context_ref', $contextRef)
            ->update(['expires_at' => now(), 'updated_at' => now()]) === 1;
    }

    public function isOpaque(string $contextRef): bool
    {
        return preg_match('/^ptok_[a-f0-9]{24}$/D', $contextRef) === 1;
    }

    private function signingKey(): string
    {
        $key = config('hummingbird.patient_context.signing_key');

        if (! is_string($key) || strlen($key) < 32) {
            throw new RuntimeException('A dedicated Hummingbird patient-context signing key is required.');
        }

        return $key;
    }

    private function ttlMinutes(): int
    {
        return max(1, min(1440, (int) config('hummingbird.patient_context.ttl_minutes', 15)));
    }
}
