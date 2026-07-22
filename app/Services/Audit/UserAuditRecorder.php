<?php

namespace App\Services\Audit;

use App\Http\Middleware\AssignRequestIdentity;
use App\Models\Audit\UserEvent;
use App\Models\User;
use App\Security\ClinicalPayloads\ClinicalContentGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

class UserAuditRecorder
{
    public const REQUEST_AUDITED_ATTRIBUTE = '_zephyrus_user_audit_recorded';

    public const AUTH_METHOD_ATTRIBUTE = '_zephyrus_auth_method';

    public const SOURCE_SURFACE_ATTRIBUTE = '_zephyrus_source_surface';

    private const REQUEST_UUID_ATTRIBUTE = '_zephyrus_audit_request_uuid';

    private const CATEGORIES = [
        'authentication', 'authorization', 'administration', 'access', 'activity', 'security',
    ];

    private const OUTCOMES = ['success', 'failure', 'denied', 'challenge'];

    private const AUTH_METHODS = ['password', 'oidc', 'demo', 'mobile_token', 'session', 'unknown'];

    private const SOURCE_SURFACES = ['web', 'web_api', 'hummingbird', 'system'];

    private const TARGET_TYPES = [
        'user', 'auth_provider', 'cockpit_metric', 'governed_change',
        'access_review_campaign', 'access_review_item', 'evidence_export',
        'user_external_identity',
        'system_health',
        'system_health_component',
        'admin_scope',
        'ai_provider_policy',
        'ancillary_barrier',
        'patient_message_thread',
    ];

    private const CHANGE_KEYS = [
        'role', 'is_active', 'must_change_password', 'provider_enabled',
        'ok_edge', 'warn_edge', 'crit_edge', 'refresh_secs',
        'display_name_changed', 'settings_changed', 'alert_template_changed',
        'auth_session_version', 'remember_token_invalidated', 'api_tokens_revoked',
        'database_sessions_revoked',
        'barrier_status',
    ];

    private const METADATA_KEYS = [
        'changed_fields', 'remembered', 'guard', 'token_kind', 'challenge_required',
        'password_change_required', 'provider_type', 'metric_key', 'page_visit',
        'step_up_method', 'governed_action', 'decision', 'change_request_uuid',
        'campaign_uuid', 'item_uuid', 'evidence_sha256',
        'subject_user_id', 'provider', 'subject_fingerprint', 'link_active',
        'external_identities_unlinked', 'mobile_devices_revoked', 'access_scopes_revoked',
        'batch_uuid', 'component_count', 'critical_count', 'warning_count', 'unknown_count',
        'organization_id', 'facility_id', 'source_id', 'scope_revision',
        'policy_key', 'policy_version', 'rolled_back_to_version', 'surface',
        'provider_mode', 'selected_profile_id', 'fallback_used', 'will_call_paid_provider',
        'capability', 'scope_id', 'member_count', 'eligible_count', 'blocked_count',
        'valid_from', 'valid_until',
        'work_item_uuid', 'pool_uuid', 'event_type', 'replayed',
    ];

    private const FORBIDDEN_KEY_FRAGMENTS = [
        'password', 'token', 'secret', 'credential', 'authorization', 'cookie', 'claim',
        'code', 'nonce', 'state', 'body', 'payload', 'mrn', 'patient', 'free_text', 'note',
    ];

    public function __construct(private readonly ClinicalContentGuard $clinicalContent) {}

    /**
     * Strictly insert an audit event. Call this inside the same DB transaction as
     * an administrative mutation when audit durability is part of the commit.
     *
     * @param  array<string, mixed>  $context
     */
    public function record(
        string $action,
        string $category,
        string $outcome,
        array $context = [],
    ): UserEvent {
        $this->assertMachineValue($action, 'action', 120, namespaced: true);
        $this->assertAllowed($category, self::CATEGORIES, 'category');
        $this->assertAllowed($outcome, self::OUTCOMES, 'outcome');

        $request = $context['request'] ?? $this->currentRequest();
        if ($request !== null && ! $request instanceof Request) {
            throw new InvalidArgumentException('Audit request context must be an HTTP request.');
        }

        $actor = $context['actor'] ?? $request?->user();
        if ($actor !== null && ! $actor instanceof User) {
            throw new InvalidArgumentException('Audit actor must be a Zephyrus user.');
        }

        $reason = $context['reason'] ?? null;
        if ($reason !== null) {
            $this->assertMachineValue((string) $reason, 'reason', 120);
        }

        $authMethod = $context['auth_method'] ?? $request?->attributes->get(self::AUTH_METHOD_ATTRIBUTE);
        if ($authMethod !== null) {
            $this->assertAllowed((string) $authMethod, self::AUTH_METHODS, 'auth method');
        }

        $sourceSurface = (string) ($context['source_surface'] ?? $this->sourceSurface($request));
        $this->assertAllowed($sourceSurface, self::SOURCE_SURFACES, 'source surface');

        $targetType = $context['target_type'] ?? null;
        if ($targetType !== null) {
            $this->assertAllowed((string) $targetType, self::TARGET_TYPES, 'target type');
        }

        $targetId = $context['target_id'] ?? null;
        if ($targetId !== null) {
            $targetId = (string) $targetId;
            if (strlen($targetId) > 190 || ! preg_match('/^[A-Za-z0-9_.:-]+$/', $targetId)) {
                throw new InvalidArgumentException('Audit target ID is not a safe machine identifier.');
            }
        }

        $httpStatus = isset($context['http_status']) ? (int) $context['http_status'] : null;
        if ($httpStatus !== null && ($httpStatus < 100 || $httpStatus > 599)) {
            throw new InvalidArgumentException('Audit HTTP status is invalid.');
        }

        $principal = $context['principal'] ?? $actor?->username ?? $actor?->email;
        $sessionId = $context['session_id'] ?? $this->sessionId($request);
        $route = $request?->route();

        $attributes = [
            'event_uuid' => (string) Str::uuid7(),
            'occurred_at' => $context['occurred_at'] ?? now(),
            'actor_user_id' => $actor?->getKey(),
            'actor_username' => $actor !== null ? Str::limit((string) $actor->username, 190, '') : null,
            'actor_role' => $actor !== null ? Str::limit((string) $actor->role, 80, '') : null,
            'principal_ref' => $this->hmacPrincipal(is_scalar($principal) ? (string) $principal : null),
            'session_ref' => $this->hmacSession(is_scalar($sessionId) ? (string) $sessionId : null),
            'action' => $action,
            'category' => $category,
            'outcome' => $outcome,
            'reason' => $reason,
            'auth_method' => $authMethod,
            'source_surface' => $sourceSurface,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'route_name' => $this->truncate($route?->getName(), 190),
            'uri_template' => $this->truncate($route?->uri(), 500),
            'http_method' => $request?->getMethod(),
            'http_status' => $httpStatus,
            'request_uuid' => $this->requestUuid($request),
            'client_ip' => $this->clientIp($request),
            'user_agent' => $this->userAgent($request),
            'changes' => $this->sanitizeChanges($context['changes'] ?? []),
            'metadata' => $this->sanitizeMetadata($context['metadata'] ?? []),
            'schema_version' => 1,
        ];
        $this->clinicalContent->assertSafe($attributes, 'clinical_content_audit_rejected');

        $event = UserEvent::query()->create($attributes);

        $this->markRequestAudited($request);

        return $event;
    }

    /**
     * Record without allowing an audit outage to break authentication or a response.
     *
     * @param  array<string, mixed>  $context
     */
    public function bestEffort(
        string $action,
        string $category,
        string $outcome,
        array $context = [],
    ): ?UserEvent {
        try {
            return $this->record($action, $category, $outcome, $context);
        } catch (Throwable $exception) {
            $request = ($context['request'] ?? null) instanceof Request ? $context['request'] : $this->currentRequest();
            $this->markRequestAudited($request);

            Log::error('user_audit.record_failed', [
                'action' => $action,
                'exception' => $exception::class,
            ]);

            return null;
        }
    }

    public function hmacPrincipal(?string $principal): ?string
    {
        $normalized = $principal !== null ? Str::lower(trim($principal)) : '';

        return $normalized === '' ? null : $this->hmac('principal', $normalized);
    }

    public function hmacSession(?string $sessionId): ?string
    {
        $normalized = $sessionId !== null ? trim($sessionId) : '';

        return $normalized === '' ? null : $this->hmac('session', $normalized);
    }

    public function markRequestAudited(?Request $request): void
    {
        $request?->attributes->set(self::REQUEST_AUDITED_ATTRIBUTE, true);
    }

    public function requestWasAudited(Request $request): bool
    {
        return $request->attributes->getBoolean(self::REQUEST_AUDITED_ATTRIBUTE);
    }

    /** @param  array<string, mixed>  $changes */
    private function sanitizeChanges(array $changes): array
    {
        return $this->sanitizeAllowlisted($changes, self::CHANGE_KEYS, allowTransitions: true);
    }

    /** @param  array<string, mixed>  $metadata */
    private function sanitizeMetadata(array $metadata): array
    {
        return $this->sanitizeAllowlisted($metadata, self::METADATA_KEYS, allowTransitions: false);
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  list<string>  $allowedRootKeys
     * @return array<string, mixed>
     */
    private function sanitizeAllowlisted(array $input, array $allowedRootKeys, bool $allowTransitions): array
    {
        $safe = [];
        foreach ($input as $key => $value) {
            $key = (string) $key;
            if (! in_array($key, $allowedRootKeys, true)) {
                continue;
            }

            $sanitized = $this->sanitizeValue($value, $allowTransitions, 0);
            if ($sanitized !== null || $value === null) {
                $safe[$key] = $sanitized;
            }
        }

        return $safe;
    }

    private function sanitizeValue(mixed $value, bool $allowTransitions, int $depth): mixed
    {
        if ($depth > 4) {
            return null;
        }

        if (is_bool($value) || is_int($value) || is_float($value) || $value === null) {
            return $value;
        }

        if (is_string($value)) {
            return Str::limit(preg_replace('/[\x00-\x1F\x7F]/u', '', $value) ?? '', 190, '');
        }

        if (! is_array($value)) {
            return null;
        }

        $safe = [];
        foreach ($value as $key => $nested) {
            if (is_string($key)) {
                if ($this->isForbiddenKey($key)) {
                    continue;
                }
                if ($allowTransitions && ! in_array($key, ['from', 'to'], true)) {
                    continue;
                }
                if (! $allowTransitions) {
                    continue;
                }
            }

            $sanitized = $this->sanitizeValue($nested, false, $depth + 1);
            if ($sanitized !== null || $nested === null) {
                $safe[$key] = $sanitized;
            }
        }

        return $safe;
    }

    private function isForbiddenKey(string $key): bool
    {
        $canonical = Str::lower($key);
        foreach (self::FORBIDDEN_KEY_FRAGMENTS as $fragment) {
            if (str_contains($canonical, $fragment)) {
                return true;
            }
        }

        return false;
    }

    private function hmac(string $domain, string $value): string
    {
        $key = (string) config('audit.hmac_key');
        if ($key === '') {
            throw new InvalidArgumentException('AUDIT_HMAC_KEY or APP_KEY must be configured.');
        }

        return hash_hmac('sha256', $domain."\0".$value, $key);
    }

    private function requestUuid(?Request $request): string
    {
        $assigned = $request?->attributes->get(AssignRequestIdentity::ATTRIBUTE);
        if (is_string($assigned) && Str::isUuid($assigned)) {
            return $assigned;
        }

        $existing = $request?->attributes->get(self::REQUEST_UUID_ATTRIBUTE);
        if (is_string($existing) && Str::isUuid($existing)) {
            return $existing;
        }

        $header = $request?->headers->get('X-Request-ID');
        $uuid = is_string($header) && Str::isUuid($header) ? $header : (string) Str::uuid7();
        $request?->attributes->set(self::REQUEST_UUID_ATTRIBUTE, $uuid);

        return $uuid;
    }

    private function sessionId(?Request $request): ?string
    {
        if ($request === null || ! $request->hasSession() || ! $request->session()->isStarted()) {
            return null;
        }

        return $request->session()->getId();
    }

    private function sourceSurface(?Request $request): string
    {
        $explicit = $request?->attributes->get(self::SOURCE_SURFACE_ATTRIBUTE);
        if (is_string($explicit) && $explicit !== '') {
            return $explicit;
        }

        if ($request === null) {
            return 'system';
        }

        if ($request->is('api/auth/*') || $request->is('api/mobile/*')) {
            return 'hummingbird';
        }

        return $request->is('api/*') || str_starts_with((string) $request->route()?->uri(), 'api/')
            ? 'web_api'
            : 'web';
    }

    private function clientIp(?Request $request): ?string
    {
        $ip = $request?->ip();

        return is_string($ip) && filter_var($ip, FILTER_VALIDATE_IP) ? $ip : null;
    }

    private function userAgent(?Request $request): ?string
    {
        $agent = $request?->userAgent();
        if (! is_string($agent) || $agent === '') {
            return null;
        }

        return $this->truncate(preg_replace('/[\x00-\x1F\x7F]/u', '', $agent), 512);
    }

    private function currentRequest(): ?Request
    {
        return app()->bound('request') && app('request') instanceof Request ? app('request') : null;
    }

    /** @param  list<string>  $allowed */
    private function assertAllowed(string $value, array $allowed, string $field): void
    {
        if (! in_array($value, $allowed, true)) {
            throw new InvalidArgumentException("Unsupported audit {$field}.");
        }
    }

    private function assertMachineValue(string $value, string $field, int $max, bool $namespaced = false): void
    {
        $pattern = $namespaced
            ? '/^[a-z][a-z0-9_-]*(\.[a-z0-9_-]+)+$/'
            : '/^[a-z][a-z0-9_.:-]*$/';

        if ($value === '' || strlen($value) > $max || ! preg_match($pattern, $value)) {
            throw new InvalidArgumentException("Audit {$field} must be a safe machine value.");
        }
    }

    private function truncate(?string $value, int $length): ?string
    {
        return $value === null || $value === '' ? null : Str::limit($value, $length, '');
    }
}
