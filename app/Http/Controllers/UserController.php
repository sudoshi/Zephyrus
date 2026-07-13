<?php

namespace App\Http\Controllers;

use App\Authorization\GovernedAction;
use App\Models\Governance\GovernedChangeRequest;
use App\Models\User;
use App\Services\Audit\UserAuditRecorder;
use App\Services\Auth\AccountLifecyclePolicy;
use App\Services\Auth\AccountLifecycleViolation;
use App\Services\Auth\AccountSessionService;
use App\Services\Auth\StepUpAuthenticationService;
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
    ) {}

    /**
     * Display a listing of the users.
     */
    public function index()
    {
        Gate::authorize('viewIdentity');

        $users = User::select('id', 'name', 'email', 'username', 'role', 'is_active', 'is_protected', 'deactivated_at', 'created_at')
            ->orderBy('name')
            ->get();

        return Inertia::render('Admin/Users/Index', [
            'users' => $users,
        ]);
    }

    /**
     * Show the form for creating a new user.
     */
    public function create()
    {
        Gate::authorize('manageIdentity');

        return Inertia::render('Admin/Users/Create');
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
    public function edit(User $user)
    {
        Gate::authorize('viewIdentity');

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

        return Inertia::render('Admin/Users/Edit', [
            'user' => [
                ...$user->only([
                    'id', 'name', 'email', 'username', 'role', 'is_active', 'is_protected',
                    'deactivated_at', 'identity_purged_at',
                ]),
                'external_identities' => $user->externalIdentities()
                    ->orderBy('provider')
                    ->get()
                    ->map(fn ($identity): array => [
                        'id' => $identity->getKey(),
                        'provider' => $identity->provider,
                        'subject_fingerprint' => substr(
                            hash('sha256', $identity->provider.':'.$identity->provider_subject),
                            0,
                            16,
                        ),
                        'provider_email_at_link' => $identity->provider_email_at_link,
                        'linked_at' => $identity->linked_at?->toIso8601String(),
                        'is_active' => (bool) $identity->is_active,
                        'unlinked_at' => $identity->unlinked_at?->toIso8601String(),
                        'relinked_at' => $identity->relinked_at?->toIso8601String(),
                    ])->values(),
                'purge_requests' => $purgeRequests,
            ],
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
