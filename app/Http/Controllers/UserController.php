<?php

namespace App\Http\Controllers;

use App\Authorization\Capability;
use App\Authorization\GovernedAction;
use App\Models\Auth\UserAccessScope;
use App\Models\Governance\GovernedChangeRequest;
use App\Models\Org\Facility;
use App\Models\Org\Organization;
use App\Models\User;
use App\Services\Audit\UserAuditRecorder;
use App\Services\Auth\AccountLifecyclePolicy;
use App\Services\Auth\AccountLifecycleViolation;
use App\Services\Auth\AccountSessionService;
use App\Services\Auth\LocalCredentialPolicy;
use App\Services\Auth\StepUpAuthenticationService;
use App\Services\Authorization\RoleCapabilityService;
use App\Services\Identity\IdentityRedactionService;
use App\Services\Identity\UserLifecycleReadService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class UserController extends Controller
{
    public function __construct(
        private readonly UserAuditRecorder $audit,
        private readonly AccountLifecyclePolicy $lifecycle,
        private readonly AccountSessionService $sessions,
        private readonly StepUpAuthenticationService $stepUp,
        private readonly LocalCredentialPolicy $localCredentials,
        private readonly UserLifecycleReadService $lifecycleRead,
        private readonly IdentityRedactionService $redaction,
        private readonly RoleCapabilityService $authorization,
    ) {}

    /**
     * Display a listing of the users.
     */
    public function index(Request $request)
    {
        Gate::authorize('viewIdentity');

        $users = User::select(
            'id', 'name', 'email', 'username', 'role', 'is_active', 'is_protected',
            'deactivated_at', 'identity_purged_at', 'provisioning_state', 'created_at',
        )
            ->orderBy('name')
            ->get();

        $revealPii = $this->redaction->canViewSensitiveIdentity($request->user());
        $summaries = $this->lifecycleRead->summaries($users);

        return Inertia::render('Admin/Users/Index', [
            'users' => $users->map(fn (User $user): array => [
                ...$user->only([
                    'id', 'name', 'username', 'role', 'is_active', 'is_protected',
                    'deactivated_at', 'identity_purged_at', 'created_at',
                ]),
                'email' => $this->redaction->presentEmail($user->email, $revealPii),
                'lifecycle' => $summaries[(int) $user->getKey()],
            ])->values(),
            'redaction' => ['piiVisible' => $revealPii],
        ]);
    }

    /**
     * Show the form for creating a new user.
     */
    public function create()
    {
        Gate::authorize('manageIdentity');

        return Inertia::render('Admin/Users/Create', [
            'sso_only' => $this->localCredentials->ssoOnly(),
        ]);
    }

    /**
     * Store a newly created user in storage.
     */
    public function store(Request $request)
    {
        Gate::authorize('manageIdentity');

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique(User::class, 'email')],
            'username' => ['required', 'string', 'max:255', Rule::unique(User::class, 'username')],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
            'role' => 'required|string|in:user,admin,superuser',
            'is_active' => 'boolean',
            'change_reason' => ['required', Rule::in(['new_account', 'approved_privileged_account'])],
        ]);

        if ($validated['role'] !== 'user') {
            Gate::authorize('managePrivileges');
            $this->stepUp->assertSatisfied($request, 'privileged_account_creation');
        }

        try {
            // New accounts are never break-glass, so SSO-only fails closed here.
            $this->localCredentials->assertLocalPasswordAllowed(null);
        } catch (AccountLifecycleViolation $exception) {
            $this->audit->record('administration.user.create_denied', 'authorization', 'denied', [
                'request' => $request,
                'reason' => $exception->reason,
                'http_status' => $request->expectsJson() ? 422 : 302,
            ]);

            throw ValidationException::withMessages([
                $exception->field => [$exception->getMessage()],
            ]);
        }

        DB::transaction(function () use ($request, $validated): void {
            $user = User::create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'username' => $validated['username'],
                'password' => Hash::make($validated['password']),
                'role' => $validated['role'],
                'is_active' => $request->boolean('is_active'),
            ]);

            $this->audit->record('administration.user.created', 'administration', 'success', [
                'request' => $request,
                'target_type' => 'user',
                'target_id' => $user->getKey(),
                'reason' => $validated['change_reason'],
                'changes' => [
                    'role' => ['from' => null, 'to' => (string) $user->role],
                    'is_active' => ['from' => null, 'to' => (bool) $user->is_active],
                    'must_change_password' => ['from' => null, 'to' => (bool) $user->must_change_password],
                ],
                'metadata' => [
                    'changed_fields' => ['display_name', 'email_address', 'username', 'credentials', 'role', 'is_active'],
                ],
            ]);
        });

        return redirect()->route('users.index')
            ->with('message', 'User created successfully.');
    }

    /**
     * Show the form for editing the specified user.
     */
    public function edit(Request $request, User $user)
    {
        Gate::authorize('viewIdentity');

        $revealPii = $this->redaction->canViewSensitiveIdentity($request->user());

        $purgeRequests = GovernedChangeRequest::query()
            ->with(['author:id,name', 'decision', 'executions'])
            ->where('action_type', GovernedAction::PurgeUserIdentity->value)
            ->where('subject_type', 'user_identity')
            ->where('subject_id', (string) $user->getKey())
            ->latest('requested_at')
            ->limit(10)
            ->get()
            ->map(function (GovernedChangeRequest $change): array {
                $executed = $change->executions->contains('outcome', 'success');
                $status = $executed
                    ? 'executed'
                    : ($change->decision?->decision ?? ($change->expires_at->isPast() ? 'expired' : 'pending'));

                return [
                    'uuid' => $change->getKey(),
                    'author_user_id' => (int) $change->author_user_id,
                    'author_name' => $change->author?->name,
                    'reason' => $change->reason,
                    'requested_at' => $change->requested_at?->toIso8601String(),
                    'expires_at' => $change->expires_at?->toIso8601String(),
                    'status' => $status,
                    'decision' => $change->decision?->decision,
                    'executed' => $executed,
                ];
            })->values();

        $scopes = UserAccessScope::query()
            ->with([
                'organization:organization_id,organization_key,name',
                'facility:facility_id,facility_key,facility_name',
                'grantedBy:id,username',
            ])
            ->where('user_id', $user->getKey())
            ->orderByDesc('id')
            ->limit(25)
            ->get()
            ->map(fn (UserAccessScope $scope): array => [
                'id' => (int) $scope->getKey(),
                'organization_id' => $scope->organization_id,
                'organization_label' => $scope->organization?->name,
                'facility_id' => $scope->facility_id,
                'facility_label' => $scope->facility?->facility_name,
                'grant_reason' => $scope->grant_reason,
                'granted_by_username' => $scope->grantedBy?->username,
                'valid_from' => $scope->valid_from?->toIso8601String(),
                'valid_until' => $scope->valid_until?->toIso8601String(),
                'revoked_at' => $scope->revoked_at?->toIso8601String(),
                'revocation_reason' => $scope->revocation_reason,
            ])->values();

        $directCapabilities = $user->permissions()
            ->orderBy('name')
            ->pluck('name')
            ->map(fn (string $name): ?string => str_starts_with($name, 'capability:')
                ? substr($name, strlen('capability:'))
                : null)
            ->filter()
            ->values();

        return Inertia::render('Admin/Users/Edit', [
            'user' => [
                ...$user->only([
                    'id', 'name', 'username', 'role', 'is_active', 'is_protected',
                    'deactivated_at', 'identity_purged_at', 'provisioning_state',
                ]),
                'email' => $this->redaction->presentEmail($user->email, $revealPii),
                'external_identities' => $user->externalIdentities()
                    ->orderBy('provider')
                    ->get()
                    ->map(fn ($identity): array => [
                        'id' => $identity->getKey(),
                        'provider' => $identity->provider,
                        'subject_fingerprint' => $this->lifecycleRead->subjectFingerprint($identity),
                        'provider_email_at_link' => $this->redaction->presentEmail($identity->provider_email_at_link, $revealPii),
                        'linked_at' => $identity->linked_at?->toIso8601String(),
                        'is_active' => (bool) $identity->is_active,
                        'unlinked_at' => $identity->unlinked_at?->toIso8601String(),
                        'relinked_at' => $identity->relinked_at?->toIso8601String(),
                    ])->values(),
                'purge_requests' => $purgeRequests,
            ],
            'lifecycle' => $this->lifecycleRead->summary($user),
            'authorization' => [
                'effective_roles' => $this->authorization->effectiveRoleIds($user),
                'effective_capabilities' => array_map(
                    fn (Capability $capability): string => $capability->value,
                    $this->authorization->effectiveCapabilities($user),
                ),
                'direct_capabilities' => $directCapabilities,
                'capability_options' => array_column(Capability::cases(), 'value'),
            ],
            'access_scopes' => $scopes,
            'scope_options' => [
                'organizations' => Organization::query()
                    ->orderBy('name')
                    ->get(['organization_id', 'organization_key', 'name'])
                    ->map(fn (Organization $organization): array => [
                        'id' => (int) $organization->organization_id,
                        'key' => $organization->organization_key,
                        'label' => $organization->name,
                    ])->values(),
                'facilities' => Facility::query()
                    ->where('is_active', true)
                    ->orderBy('facility_name')
                    ->get(['facility_id', 'facility_key', 'facility_name'])
                    ->map(fn (Facility $facility): array => [
                        'id' => (int) $facility->facility_id,
                        'key' => $facility->facility_key,
                        'label' => $facility->facility_name,
                    ])->values(),
            ],
            'redaction' => ['piiVisible' => $revealPii],
            'sso_only' => $this->localCredentials->ssoOnly(),
        ]);
    }

    /**
     * Update the specified user in storage.
     */
    public function update(Request $request, User $user)
    {
        Gate::authorize('manageIdentity');

        $rules = [
            'name' => 'required|string|max:255',
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique(User::class, 'email')->ignore($user)],
            'username' => ['required', 'string', 'max:255', Rule::unique(User::class, 'username')->ignore($user)],
            'role' => 'required|string|in:user,admin,superuser',
            'is_active' => 'boolean',
            'change_reason' => ['required', Rule::in([
                'routine_profile_update',
                'identity_correction',
                'role_change_approved',
                'account_deactivation',
                'account_reactivation',
                'credential_reset',
            ])],
        ];

        // Only validate password if it's provided
        if ($request->filled('password')) {
            $rules['password'] = ['confirmed', Rules\Password::defaults()];
        }

        $validated = $request->validate($rules);

        if ((string) $validated['role'] !== (string) $user->role) {
            Gate::authorize('managePrivileges');
            if (! $request->user()->is($user)) {
                $this->stepUp->assertSatisfied($request, 'privilege_assignment_changed');
            }
        }

        $userData = [
            'name' => $validated['name'],
            'email' => $validated['email'],
            'username' => $validated['username'],
            'role' => $validated['role'],
            'is_active' => $request->boolean('is_active'),
            'deactivated_at' => $request->boolean('is_active') ? null : now(),
        ];

        // Only update password if it's provided
        if ($request->filled('password')) {
            $userData['password'] = Hash::make($request->password);
        }

        try {
            DB::transaction(function () use ($request, $user, $userData, $validated): void {
                $target = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();
                $before = $target->only(['name', 'email', 'username', 'role', 'is_active', 'must_change_password']);
                $credentialsChanging = array_key_exists('password', $userData);
                $identityChanging = $before['email'] !== $userData['email']
                    || $before['username'] !== $userData['username'];

                if ($credentialsChanging) {
                    // SSO-only deployments allow local passwords only on
                    // sealed break-glass accounts.
                    $this->localCredentials->assertLocalPasswordAllowed($target);
                }

                $this->lifecycle->assertUpdateAllowed(
                    $request->user(),
                    $target,
                    (string) $userData['role'],
                    (bool) $userData['is_active'],
                    $credentialsChanging,
                    $identityChanging,
                );

                $target->update($userData);

                $changedFields = [];
                $fieldLabels = [
                    'name' => 'display_name',
                    'email' => 'email_address',
                    'username' => 'username',
                    'role' => 'role',
                    'is_active' => 'is_active',
                ];
                foreach ($fieldLabels as $field => $label) {
                    if ($before[$field] !== $target->{$field}) {
                        $changedFields[] = $label;
                    }
                }
                if ($credentialsChanging) {
                    $changedFields[] = 'credentials';
                }

                $changes = [];
                foreach (['role', 'is_active', 'must_change_password'] as $field) {
                    if ($before[$field] !== $target->{$field}) {
                        $changes[$field] = [
                            'from' => $before[$field],
                            'to' => $target->{$field},
                        ];
                    }
                }

                $accessSensitive = $credentialsChanging
                    || $identityChanging
                    || $before['role'] !== $target->role
                    || (bool) $before['is_active'] !== (bool) $target->is_active;
                if ($accessSensitive) {
                    $this->sessions->revoke(
                        $target,
                        $request,
                        (string) $validated['change_reason'],
                        keepCurrentSession: $request->user()->is($target),
                    );
                }

                $this->audit->record('administration.user.updated', 'administration', 'success', [
                    'request' => $request,
                    'reason' => $validated['change_reason'],
                    'target_type' => 'user',
                    'target_id' => $target->getKey(),
                    'changes' => $changes,
                    'metadata' => ['changed_fields' => $changedFields],
                ]);
            });
        } catch (AccountLifecycleViolation $exception) {
            $this->audit->record('administration.user.update_denied', 'authorization', 'denied', [
                'request' => $request,
                'reason' => $exception->reason,
                'target_type' => 'user',
                'target_id' => $user->getKey(),
                'http_status' => $request->expectsJson() ? 422 : 302,
            ]);

            throw ValidationException::withMessages([
                $exception->field => [$exception->getMessage()],
            ]);
        }

        return redirect()->route('users.index')
            ->with('message', 'User updated successfully.');
    }

    /**
     * Remove the specified user from storage.
     */
    public function destroy(Request $request, User $user)
    {
        Gate::authorize('manageIdentity');

        try {
            $this->lifecycle->denyHardDelete();
        } catch (AccountLifecycleViolation $exception) {
            $this->audit->record('administration.user.delete_denied', 'authorization', 'denied', [
                'request' => $request,
                'reason' => $exception->reason,
                'target_type' => 'user',
                'target_id' => $user->getKey(),
                'http_status' => $request->expectsJson() ? 422 : 302,
            ]);

            throw ValidationException::withMessages([
                $exception->field => [$exception->getMessage()],
            ]);
        }
    }
}
