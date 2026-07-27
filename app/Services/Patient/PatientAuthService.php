<?php

namespace App\Services\Patient;

use App\Models\Patient\PatientEncounterAccessGrant;
use App\Models\Patient\PatientEnrollmentChallenge;
use App\Models\Patient\PatientIdentityLink;
use App\Models\Patient\PatientPrincipal;
use App\Models\Patient\PatientSession;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;
use Throwable;

class PatientAuthService
{
    /** A valid hash used to keep unknown-account password checks comparable. */
    private const DUMMY_PASSWORD_HASH = '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.';

    public function __construct(
        private readonly PatientAccessAuditRecorder $audit,
        private readonly PatientHmac $hmac,
    ) {}

    /**
     * Verify a two-part, short-lived encounter challenge and create or bind the
     * patient principal. Neither raw challenge material nor source identifiers
     * are persisted by this path.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function enroll(array $input, Request $request): array
    {
        try {
            $result = DB::transaction(function () use ($input, $request): array {
                // Verification, activation, consumption, and token issuance
                // share one row lock and transaction. Splitting verification
                // from consumption would allow a request that merely knows the
                // challenge UUID to race a legitimate verifier after the first
                // lock is released.
                $challenge = PatientEnrollmentChallenge::query()
                    ->where('challenge_uuid', $input['challenge_uuid'])
                    ->lockForUpdate()
                    ->first();

                if (! $challenge || ! $this->challengeIsUsable($challenge)) {
                    return ['pair' => null, 'failure' => $this->invalidEnrollment()];
                }

                if (! $this->challengeSecretsMatch($challenge, $input)) {
                    $this->recordChallengeFailure($challenge);

                    return ['pair' => null, 'failure' => $this->invalidEnrollment()];
                }

                $grant = PatientEncounterAccessGrant::query()
                    ->whereKey($challenge->access_grant_id)
                    ->lockForUpdate()
                    ->first();

                if (! $grant || ! $this->grantIsUsable($grant)) {
                    throw $this->invalidEnrollment();
                }

                $identityLink = $challenge->identity_link_id !== null
                    ? PatientIdentityLink::query()->whereKey($challenge->identity_link_id)->lockForUpdate()->first()
                    : null;

                if (! $identityLink || $identityLink->status !== 'verified' || $identityLink->revoked_at !== null) {
                    throw $this->invalidEnrollment();
                }

                $principal = $challenge->principal_id !== null
                    ? PatientPrincipal::query()->whereKey($challenge->principal_id)->lockForUpdate()->first()
                    : null;

                $normalizedEmail = Str::lower(trim((string) $input['email']));

                if ($principal === null
                    || ! in_array($principal->status, ['pending', 'active'], true)
                    || ($principal->email !== null && Str::lower((string) $principal->email) !== $normalizedEmail)
                    || ($principal->status === 'active'
                        && ! $this->passwordMatches((string) $input['password'], (string) $principal->password))) {
                    $this->recordChallengeFailure($challenge);

                    return ['pair' => null, 'failure' => $this->invalidEnrollment()];
                }

                if ($principal->status === 'pending') {
                    if (PatientPrincipal::query()
                        ->whereRaw('lower(email) = ?', [$normalizedEmail])
                        ->where($principal->getKeyName(), '<>', $principal->getKey())
                        ->exists()) {
                        throw $this->invalidEnrollment();
                    }

                    $principal->display_name = trim((string) $input['display_name']);
                    $principal->email = $normalizedEmail;
                    $principal->password = (string) $input['password'];
                    $principal->status = 'active';
                    $principal->is_active = true;
                }

                if (($identityLink->principal_id !== null && (int) $identityLink->principal_id !== (int) $principal->getKey())
                    || ($grant->principal_id !== null && (int) $grant->principal_id !== (int) $principal->getKey())) {
                    throw $this->invalidEnrollment();
                }

                $identityLink->verified_at ??= now();
                $identityLink->save();

                $grant->status = 'active';
                $grant->save();

                $challenge->principal_id = $principal->getKey();
                $challenge->status = 'consumed';
                $challenge->consumed_at = now();
                $challenge->save();

                $principal->last_authenticated_at = now();
                $principal->save();

                return [
                    'pair' => $this->issueNewSession(
                        $principal,
                        (array) ($input['device'] ?? []),
                        $request,
                        'enrollment',
                    ),
                    'failure' => null,
                ];
            });
        } catch (PatientAuthFailure $failure) {
            $this->audit->bestEffort(
                $request,
                'patient.auth.enrollment_denied',
                'authentication',
                'enroll',
                'denied',
                reasonCode: $failure->errorCode,
            );

            throw $failure;
        }

        if ($result['failure'] instanceof PatientAuthFailure) {
            $this->audit->bestEffort(
                $request,
                'patient.auth.enrollment_denied',
                'authentication',
                'enroll',
                'denied',
                reasonCode: $result['failure']->errorCode,
            );

            throw $result['failure'];
        }

        return $result['pair'];
    }

    /**
     * @param  array<string, mixed>  $device
     * @return array<string, mixed>
     */
    public function authenticate(string $email, string $password, array $device, Request $request): array
    {
        $normalizedEmail = Str::lower(trim($email));
        $principal = PatientPrincipal::query()
            ->whereRaw('lower(email) = ?', [$normalizedEmail])
            ->first();

        $validPassword = $this->passwordMatches($password, (string) ($principal?->password ?? ''));

        if (! $principal || ! $validPassword) {
            $this->audit->bestEffort(
                $request,
                'patient.auth.token_denied',
                'authentication',
                'token_exchange',
                'denied',
                reasonCode: 'invalid_credentials',
            );

            throw new PatientAuthFailure(
                'invalid_credentials',
                'These credentials do not match our records.',
                401,
            );
        }

        try {
            $this->assertPrincipalCanAuthenticate($principal);
        } catch (PatientAuthFailure $failure) {
            $this->audit->bestEffort(
                $request,
                'patient.auth.token_denied',
                'authentication',
                'token_exchange',
                'denied',
                $principal,
                reasonCode: $failure->errorCode,
                resourceType: 'patient_principal',
                resourceUuid: (string) $principal->principal_uuid,
            );

            throw $failure;
        }

        $principal->forceFill(['last_authenticated_at' => now()])->save();

        return DB::transaction(
            fn (): array => $this->issueNewSession($principal, $device, $request, 'password'),
        );
    }

    /** @return array<string, mixed> */
    public function refresh(PatientPrincipal $principal, PersonalAccessToken $token, Request $request): array
    {
        $result = DB::transaction(function () use ($principal, $token, $request): array {
            $this->assertPrincipalCanAuthenticate($principal);
            $session = $this->sessionForToken($principal, $token, forUpdate: true);

            if (! $session
                || ! str_starts_with((string) $token->name, 'patient-refresh:')
                || (int) $session->refresh_token_id !== (int) $token->getKey()
                || $session->revoked_at !== null
                || $session->expires_at === null
                || ! $session->expires_at->isFuture()
                || ($session->idle_expires_at !== null && ! $session->idle_expires_at->isFuture())) {
                $token->delete();
                $this->revokeSession($principal, $session, 'invalid_refresh_token');

                return ['pair' => null, 'denied_session' => $session];
            }

            // Retain the predecessor refresh-token hash until the family is
            // revoked or expires. If that predecessor is presented again, the
            // refresh_token_id mismatch below is theft/reuse evidence and the
            // whole family is revoked. Access predecessors are revoked now.
            $this->deleteSessionAccessTokens($principal, (string) $session->session_uuid);

            $pair = $this->issueForSession($principal, $session, $request);
            $this->audit->record(
                $request,
                'patient.auth.token_refreshed',
                'authentication',
                'token_refresh',
                'succeeded',
                $principal,
                $session,
                resourceType: 'patient_session',
                resourceUuid: (string) $session->session_uuid,
            );

            return ['pair' => $pair, 'denied_session' => null];
        });

        if ($result['pair'] === null) {
            /** @var PatientSession|null $deniedSession */
            $deniedSession = $result['denied_session'];
            $this->audit->bestEffort(
                $request,
                'patient.auth.refresh_denied',
                'authentication',
                'token_refresh',
                'denied',
                $principal,
                $deniedSession,
                reasonCode: 'invalid_refresh_token',
                resourceType: 'patient_session',
                resourceUuid: $deniedSession?->session_uuid,
            );

            throw new PatientAuthFailure(
                'invalid_refresh_token',
                'A valid patient refresh token is required.',
                401,
            );
        }

        return $result['pair'];
    }

    public function revoke(PatientPrincipal $principal, PersonalAccessToken $token, Request $request): void
    {
        $session = DB::transaction(function () use ($principal, $token): ?PatientSession {
            $session = $this->sessionForToken($principal, $token, forUpdate: true);
            $this->revokeSession($principal, $session, 'user_logout');
            $token->delete();

            return $session;
        });

        $this->audit->bestEffort(
            $request,
            'patient.auth.token_revoked',
            'authentication',
            'token_revoke',
            'succeeded',
            $principal,
            $session,
            resourceType: 'patient_session',
            resourceUuid: $session?->session_uuid,
        );
    }

    /**
     * Return only currently usable sessions owned by this patient principal.
     * Network and fingerprint material never crosses this service boundary.
     *
     * @return array{current_session_uuid: string|null, sessions: Collection<int, PatientSession>}
     */
    public function activeSessions(
        PatientPrincipal $principal,
        PersonalAccessToken $currentToken,
        Request $request,
    ): array {
        $currentSession = $this->sessionForToken($principal, $currentToken);
        $sessions = PatientSession::query()
            ->where('principal_id', $principal->getKey())
            ->active()
            ->orderByDesc('last_seen_at')
            ->orderByDesc('created_at')
            ->limit(100)
            ->get();

        // The public contract caps the collection at 100. A currently valid
        // device must still be represented even if many newer device sessions
        // exist, so replace the final row rather than exceeding that bound.
        if ($currentSession !== null
            && ! $sessions->contains(
                fn (PatientSession $session): bool => $session->is($currentSession),
            )
        ) {
            $sessions = collect([$currentSession])
                ->concat($sessions)
                ->take(100)
                ->values();
        }

        $this->audit->record(
            $request,
            'patient.auth.sessions_viewed',
            'access',
            'list_sessions',
            'allowed',
            $principal,
            $currentSession,
            resourceType: 'patient_session_collection',
            metadata: ['active_session_count' => $sessions->count()],
        );

        return [
            'current_session_uuid' => $currentSession?->session_uuid,
            'sessions' => $sessions,
        ];
    }

    /**
     * Revoke one session owned by the authenticated principal. Repeating the
     * same request is safe and reports an already-revoked result; a session
     * owned by another principal is indistinguishable from an unknown UUID.
     *
     * @return array{session: PatientSession, already_revoked: bool}|null
     */
    public function revokeOwnedSession(
        PatientPrincipal $principal,
        string $sessionUuid,
        PersonalAccessToken $currentToken,
        Request $request,
    ): ?array {
        $result = DB::transaction(function () use (
            $principal,
            $sessionUuid,
            $currentToken,
            $request,
        ): ?array {
            $session = PatientSession::query()
                ->where('principal_id', $principal->getKey())
                ->where('session_uuid', $sessionUuid)
                ->lockForUpdate()
                ->first();

            if (! $session) {
                return null;
            }

            $alreadyRevoked = $session->status === 'revoked';
            if (! $alreadyRevoked) {
                $this->revokeSession($principal, $session, 'user_session_revoked');
            }

            $currentSession = $this->sessionForToken($principal, $currentToken);
            $this->audit->record(
                $request,
                'patient.auth.session_revoked',
                'authentication',
                'revoke_session',
                'succeeded',
                $principal,
                $currentSession,
                resourceType: 'patient_session',
                resourceUuid: (string) $session->session_uuid,
                metadata: [
                    'already_revoked' => $alreadyRevoked,
                    'current_session' => $currentSession?->is($session) ?? false,
                ],
            );

            return ['session' => $session, 'already_revoked' => $alreadyRevoked];
        });

        if ($result === null) {
            $this->audit->bestEffort(
                $request,
                'patient.auth.session_revoke_denied',
                'authentication',
                'revoke_session',
                'denied',
                $principal,
                reasonCode: 'not_found',
                resourceType: 'patient_session',
                resourceUuid: $sessionUuid,
            );
        }

        return $result;
    }

    private function challengeIsUsable(PatientEnrollmentChallenge $challenge): bool
    {
        return $challenge->isUsable()
            && $challenge->consumed_at === null
            && $challenge->revoked_at === null;
    }

    private function grantIsUsable(PatientEncounterAccessGrant $grant): bool
    {
        return in_array($grant->status, ['pending', 'active'], true)
            && $grant->revoked_at === null
            && ($grant->valid_from === null || $grant->valid_from->isPast())
            && ($grant->expires_at === null || $grant->expires_at->isFuture());
    }

    private function recordChallengeFailure(PatientEnrollmentChallenge $challenge): void
    {
        $challenge->failed_attempts = (int) $challenge->failed_attempts + 1;

        if ((int) $challenge->failed_attempts >= (int) $challenge->max_attempts) {
            $challenge->status = 'locked';
        }

        $challenge->save();
    }

    private function invalidEnrollment(): PatientAuthFailure
    {
        return new PatientAuthFailure(
            'invalid_enrollment_challenge',
            'The enrollment challenge is invalid or no longer available.',
            422,
        );
    }

    private function assertPrincipalCanAuthenticate(PatientPrincipal $principal): void
    {
        if (! (bool) $principal->is_active || $principal->status !== 'active') {
            throw new PatientAuthFailure(
                'account_inactive',
                'This patient account is not active.',
                403,
            );
        }

        if ($principal->locked_at !== null) {
            throw new PatientAuthFailure(
                'account_locked',
                'This patient account is temporarily unavailable.',
                423,
            );
        }
    }

    /**
     * @param  array<string, mixed>  $device
     * @return array<string, mixed>
     */
    private function issueNewSession(
        PatientPrincipal $principal,
        array $device,
        Request $request,
        string $authMethod,
    ): array {
        $sessionUuid = (string) Str::uuid7();
        $refreshExpiresAt = CarbonImmutable::now()->addDays(
            (int) config('hummingbird-patient.token.refresh_ttl_days', 14),
        );

        $session = PatientSession::query()->create([
            'session_uuid' => $sessionUuid,
            'principal_id' => $principal->getKey(),
            'token_family_uuid' => (string) Str::uuid7(),
            'device_uuid' => $device['uuid'] ?? null,
            'platform' => $device['platform'] ?? null,
            'device_name' => $device['name'] ?? null,
            'app_version' => $device['app_version'] ?? null,
            'os_version' => $device['os_version'] ?? null,
            'auth_method' => $authMethod,
            'assurance_level' => $authMethod === 'enrollment' ? 'enrollment_verified' : 'password',
            'client_instance_digest' => $this->clientFingerprint($device, $request),
            'user_agent_digest' => hash('sha256', (string) $request->userAgent()),
            'ip_address' => $request->ip(),
            'status' => 'active',
            'last_authenticated_at' => now(),
            'last_seen_at' => now(),
            'expires_at' => $refreshExpiresAt,
            'idle_expires_at' => $refreshExpiresAt,
        ]);

        $pair = $this->issueForSession($principal, $session, $request);
        $this->audit->record(
            $request,
            $authMethod === 'enrollment' ? 'patient.auth.enrollment_succeeded' : 'patient.auth.token_issued',
            'authentication',
            $authMethod === 'enrollment' ? 'enroll' : 'token_exchange',
            'succeeded',
            $principal,
            $session,
            resourceType: 'patient_session',
            resourceUuid: (string) $session->session_uuid,
            metadata: ['auth_method' => $authMethod],
        );

        return $pair;
    }

    /** @return array<string, mixed> */
    private function issueForSession(
        PatientPrincipal $principal,
        PatientSession $session,
        Request $request,
    ): array {
        $accessTtlMinutes = (int) config('hummingbird-patient.token.access_ttl_minutes', 15);
        $configuredRefreshExpiry = CarbonImmutable::now()->addDays(
            (int) config('hummingbird-patient.token.refresh_ttl_days', 14),
        );
        $sessionExpiry = CarbonImmutable::instance($session->expires_at);
        $refreshExpiresAt = $configuredRefreshExpiry->lessThan($sessionExpiry)
            ? $configuredRefreshExpiry
            : $sessionExpiry;

        $access = $principal->createToken(
            'patient-access:'.$session->session_uuid,
            ['patient:access'],
            now()->addMinutes($accessTtlMinutes),
        );
        $refresh = $principal->createToken(
            'patient-refresh:'.$session->session_uuid,
            ['patient:refresh'],
            $refreshExpiresAt,
        );

        $session->forceFill([
            'status' => 'active',
            'refresh_token_id' => $refresh->accessToken->getKey(),
            'last_authenticated_at' => now(),
            'last_seen_at' => now(),
            'idle_expires_at' => $refreshExpiresAt,
            'ip_address' => $request->ip(),
        ])->save();

        return [
            'token_type' => 'Bearer',
            'access_token' => $access->plainTextToken,
            'refresh_token' => $refresh->plainTextToken,
            'expires_in' => $accessTtlMinutes * 60,
            'session_uuid' => (string) $session->session_uuid,
            'abilities' => ['patient:access'],
        ];
    }

    private function sessionForToken(
        PatientPrincipal $principal,
        PersonalAccessToken $token,
        bool $forUpdate = false,
    ): ?PatientSession {
        $name = (string) $token->name;
        $separator = strpos($name, ':');
        $sessionUuid = $separator === false ? '' : substr($name, $separator + 1);

        if (! Str::isUuid($sessionUuid)) {
            return null;
        }

        $query = PatientSession::query()
            ->where('session_uuid', $sessionUuid)
            ->where('principal_id', $principal->getKey());

        return ($forUpdate ? $query->lockForUpdate() : $query)->first();
    }

    private function revokeSession(
        PatientPrincipal $principal,
        ?PatientSession $session,
        string $reason,
    ): void {
        if (! $session) {
            return;
        }

        $this->deleteSessionTokens($principal, (string) $session->session_uuid);
        $session->forceFill([
            'status' => 'revoked',
            'revoked_at' => now(),
            'revocation_reason' => $reason,
            'refresh_token_id' => null,
        ])->save();
    }

    private function deleteSessionTokens(PatientPrincipal $principal, string $sessionUuid): void
    {
        $principal->tokens()
            ->whereIn('name', [
                'patient-access:'.$sessionUuid,
                'patient-refresh:'.$sessionUuid,
            ])
            ->delete();
    }

    private function deleteSessionAccessTokens(PatientPrincipal $principal, string $sessionUuid): void
    {
        $principal->tokens()
            ->where('name', 'patient-access:'.$sessionUuid)
            ->delete();
    }

    /** @param  array<string, mixed>  $device */
    private function clientFingerprint(array $device, Request $request): string
    {
        $material = implode('|', [
            (string) ($device['uuid'] ?? ''),
            (string) ($device['platform'] ?? ''),
            (string) $request->userAgent(),
        ]);

        return $this->hmac->digest('client-instance', $material);
    }

    /** @param  array<string, mixed>  $input */
    private function challengeSecretsMatch(PatientEnrollmentChallenge $challenge, array $input): bool
    {
        try {
            return $challenge->matchesChallengeToken((string) $input['challenge_token'])
                && $challenge->matchesVerificationCode((string) $input['verification_code']);
        } catch (Throwable) {
            return false;
        }
    }

    private function passwordMatches(string $password, string $storedHash): bool
    {
        $candidateHash = $storedHash !== '' ? $storedHash : self::DUMMY_PASSWORD_HASH;

        try {
            $matches = Hash::check($password, $candidateHash);
        } catch (Throwable) {
            return false;
        }

        return $storedHash !== '' && $matches;
    }
}
