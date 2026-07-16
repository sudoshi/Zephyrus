<?php

namespace App\Integrations\Healthcare\Services;

use App\Integrations\Healthcare\Exceptions\IntegrationProtocolException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * INT-SECRET — explicit mTLS server-peer trust/pinning policy metadata.
 *
 * A partner contract can require controls beyond normal CA + server-name
 * verification: an SPKI/cert fingerprint pin (or issuer-DN constraint), an
 * expected peer subject/SAN, a required flag, and an effective interval. This
 * service manages that metadata for a source network route and evaluates a
 * PRESENTED peer certificate against it. When a required pin is in force and
 * the presented material does not match, evaluation FAILS CLOSED.
 *
 * The stored fingerprints are public artifacts of the peer certificate, never
 * private key material. Enforcement is metadata-driven; no live partner is
 * needed to evaluate a synthetic peer certificate.
 */
final class PeerPinPolicyService
{
    /** @var list<string> */
    public const PIN_MODES = ['none', 'spki_sha256', 'cert_sha256', 'issuer_dn'];

    public function __construct(private readonly CertificateInspector $certificates) {}

    /** @return list<array<string, mixed>> */
    public function policies(int $sourceId): array
    {
        return DB::table('integration.source_peer_pin_policies')
            ->where('source_id', $sourceId)
            ->orderByDesc('source_peer_pin_policy_id')
            ->get()
            ->map(fn (object $row): array => $this->payload($row))
            ->all();
    }

    /**
     * Create or replace the active pin policy for a route. Only one non-retired
     * policy may attach to a route; a new active policy retires the prior one.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function upsert(int $sourceId, int $routeId, array $input, ?int $actorUserId): array
    {
        $route = DB::table('integration.source_network_routes')
            ->where('source_id', $sourceId)
            ->where('source_network_route_id', $routeId)
            ->first();
        if ($route === null) {
            throw ValidationException::withMessages(['route' => 'The network route does not belong to the source.']);
        }
        $normalized = $this->normalize($input);

        return DB::transaction(function () use ($sourceId, $routeId, $normalized, $actorUserId): array {
            DB::table('integration.source_peer_pin_policies')
                ->where('source_network_route_id', $routeId)
                ->where('status', '<>', 'retired')
                ->update(['status' => 'retired', 'updated_at' => now()]);
            $policyId = (int) DB::table('integration.source_peer_pin_policies')->insertGetId([
                'policy_uuid' => (string) Str::uuid7(),
                'source_id' => $sourceId,
                'source_network_route_id' => $routeId,
                ...$normalized,
                'status' => 'active',
                'created_by_user_id' => $actorUserId,
                'created_at' => now(),
                'updated_at' => now(),
            ], 'source_peer_pin_policy_id');

            return $this->payload($this->policy($policyId));
        });
    }

    public function retire(int $sourceId, int $policyId, string $reason): void
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 10 || mb_strlen($reason) > 500) {
            throw ValidationException::withMessages(['reason' => 'Pin policy retirement requires a 10–500 character reason.']);
        }
        $policy = DB::table('integration.source_peer_pin_policies')
            ->where('source_id', $sourceId)
            ->where('source_peer_pin_policy_id', $policyId)
            ->first();
        if ($policy === null) {
            throw ValidationException::withMessages(['policy' => 'The pin policy does not belong to the source.']);
        }
        DB::table('integration.source_peer_pin_policies')
            ->where('source_peer_pin_policy_id', $policyId)
            ->update(['status' => 'retired', 'change_reason' => $reason, 'updated_at' => now()]);
    }

    /** The active, in-effect pin policy for a route, if one exists. */
    public function activeForRoute(int $routeId, ?CarbonImmutable $now = null): ?object
    {
        $now ??= CarbonImmutable::now();

        return DB::table('integration.source_peer_pin_policies')
            ->where('source_network_route_id', $routeId)
            ->where('status', 'active')
            ->where('pin_mode', '<>', 'none')
            ->orderByDesc('source_peer_pin_policy_id')
            ->get()
            ->first(fn (object $policy): bool => $this->inEffect($policy, $now));
    }

    /**
     * Enforce a route's pin policy against a PRESENTED server-peer certificate.
     * Fails closed: a required policy with no presented certificate, or a
     * mismatch on the pinned mode, throws an IntegrationProtocolException.
     */
    public function enforceForRoute(int $routeId, ?string $peerCertificatePem, ?CarbonImmutable $now = null): void
    {
        $now ??= CarbonImmutable::now();
        $policy = $this->activeForRoute($routeId, $now);
        if ($policy === null) {
            return;
        }
        if ($peerCertificatePem === null || trim($peerCertificatePem) === '') {
            if ((bool) $policy->required) {
                throw new IntegrationProtocolException('network_peer_pin_certificate_missing');
            }

            return;
        }

        $presented = $this->certificates->peerFingerprints($peerCertificatePem);
        $pinned = $this->decodeFingerprints($policy->pinned_fingerprints);
        $matches = match ((string) $policy->pin_mode) {
            'spki_sha256' => in_array($presented['spkiSha256'], $pinned, true),
            'cert_sha256' => in_array($presented['certSha256'], $pinned, true),
            'issuer_dn' => $presented['issuerDn'] !== null
                && strcasecmp($presented['issuerDn'], (string) $policy->pinned_issuer_dn) === 0,
            default => true,
        };
        if (! $matches && (bool) $policy->required) {
            throw new IntegrationProtocolException('network_peer_pin_mismatch');
        }
        if (filled($policy->expected_peer_subject)
            && ($presented['subjectDn'] === null
                || strcasecmp($presented['subjectDn'], (string) $policy->expected_peer_subject) !== 0)
            && (bool) $policy->required) {
            throw new IntegrationProtocolException('network_peer_subject_mismatch');
        }
        if (filled($policy->expected_peer_san)
            && ! in_array('dns:'.strtolower((string) $policy->expected_peer_san), $presented['subjectAltNames'], true)
            && (bool) $policy->required) {
            throw new IntegrationProtocolException('network_peer_san_mismatch');
        }
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function normalize(array $input): array
    {
        $mode = (string) ($input['pin_mode'] ?? 'none');
        if (! in_array($mode, self::PIN_MODES, true)) {
            throw ValidationException::withMessages(['pin_mode' => 'The pin mode is not supported.']);
        }
        $reason = trim((string) ($input['change_reason'] ?? ''));
        if (mb_strlen($reason) < 10 || mb_strlen($reason) > 500) {
            throw ValidationException::withMessages(['change_reason' => 'Pin policy changes require a 10–500 character reason.']);
        }
        $fingerprints = array_values(array_unique(array_map(
            fn (mixed $value): string => strtolower(trim((string) $value)),
            (array) ($input['pinned_fingerprints'] ?? []),
        )));
        foreach ($fingerprints as $fingerprint) {
            if (preg_match('/^[0-9a-f]{64}$/', $fingerprint) !== 1) {
                throw ValidationException::withMessages(['pinned_fingerprints' => 'Every pinned fingerprint must be a SHA-256 hex digest.']);
            }
        }
        if (in_array($mode, ['spki_sha256', 'cert_sha256'], true) && $fingerprints === []) {
            throw ValidationException::withMessages(['pinned_fingerprints' => 'Fingerprint pin modes require at least one fingerprint.']);
        }
        $issuerDn = $this->normalizeOptional($input['pinned_issuer_dn'] ?? null, 400);
        if ($mode === 'issuer_dn' && $issuerDn === null) {
            throw ValidationException::withMessages(['pinned_issuer_dn' => 'The issuer-DN pin mode requires a pinned issuer distinguished name.']);
        }
        $effectiveFrom = $this->normalizeTimestamp($input['effective_from'] ?? null);
        $effectiveUntil = $this->normalizeTimestamp($input['effective_until'] ?? null);
        if ($effectiveFrom !== null && $effectiveUntil !== null && $effectiveUntil->lessThanOrEqualTo($effectiveFrom)) {
            throw ValidationException::withMessages(['effective_until' => 'The effective window must end after it begins.']);
        }

        return [
            'pin_mode' => $mode,
            'pinned_fingerprints' => json_encode($fingerprints, JSON_THROW_ON_ERROR),
            'pinned_issuer_dn' => $issuerDn,
            'expected_peer_subject' => $this->normalizeOptional($input['expected_peer_subject'] ?? null, 400),
            'expected_peer_san' => $this->normalizeOptional($input['expected_peer_san'] ?? null, 253),
            'required' => (bool) ($input['required'] ?? false),
            'effective_from' => $effectiveFrom,
            'effective_until' => $effectiveUntil,
            'change_reason' => $reason,
        ];
    }

    private function inEffect(object $policy, CarbonImmutable $now): bool
    {
        if ($policy->effective_from !== null && CarbonImmutable::parse((string) $policy->effective_from)->greaterThan($now)) {
            return false;
        }
        if ($policy->effective_until !== null && CarbonImmutable::parse((string) $policy->effective_until)->lessThanOrEqualTo($now)) {
            return false;
        }

        return true;
    }

    /** @return list<string> */
    private function decodeFingerprints(mixed $value): array
    {
        $decoded = is_string($value) ? json_decode($value, true) : $value;

        return is_array($decoded) ? array_values(array_filter($decoded, 'is_string')) : [];
    }

    /** @return array<string, mixed> */
    private function payload(object $policy): array
    {
        return [
            'peerPinPolicyId' => (int) $policy->source_peer_pin_policy_id,
            'policyUuid' => (string) $policy->policy_uuid,
            'sourceId' => (int) $policy->source_id,
            'networkRouteId' => (int) $policy->source_network_route_id,
            'pinMode' => (string) $policy->pin_mode,
            'pinnedFingerprints' => $this->decodeFingerprints($policy->pinned_fingerprints),
            'pinnedIssuerDn' => $policy->pinned_issuer_dn,
            'expectedPeerSubject' => $policy->expected_peer_subject,
            'expectedPeerSan' => $policy->expected_peer_san,
            'required' => (bool) $policy->required,
            'effectiveFromIso' => $policy->effective_from !== null ? CarbonImmutable::parse((string) $policy->effective_from)->toIso8601String() : null,
            'effectiveUntilIso' => $policy->effective_until !== null ? CarbonImmutable::parse((string) $policy->effective_until)->toIso8601String() : null,
            'status' => (string) $policy->status,
        ];
    }

    private function policy(int $policyId): object
    {
        return DB::table('integration.source_peer_pin_policies')
            ->where('source_peer_pin_policy_id', $policyId)
            ->firstOrFail();
    }

    private function normalizeOptional(mixed $value, int $max): ?string
    {
        $value = filled($value) ? trim((string) $value) : null;
        if ($value !== null && mb_strlen($value) > $max) {
            throw ValidationException::withMessages(['pin_policy' => 'A pin policy field exceeds its maximum length.']);
        }

        return $value === '' ? null : $value;
    }

    private function normalizeTimestamp(mixed $value): ?CarbonImmutable
    {
        if (! filled($value)) {
            return null;
        }
        try {
            return CarbonImmutable::parse((string) $value);
        } catch (\Throwable) {
            throw ValidationException::withMessages(['effective_interval' => 'The effective interval timestamps must be valid.']);
        }
    }
}
