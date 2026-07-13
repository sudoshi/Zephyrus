<?php

namespace App\Http\Controllers\Admin;

use App\Authorization\Capability;
use App\Http\Controllers\Controller;
use App\Models\Auth\UserAccessScope;
use App\Models\User;
use App\Services\Audit\UserAuditRecorder;
use App\Services\Auth\AccountLifecycleViolation;
use App\Services\Auth\StepUpAuthenticationService;
use App\Services\Authorization\UserAccessProvisioningService;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class UserAccessAssignmentController extends Controller
{
    public function __construct(
        private readonly UserAccessProvisioningService $provisioning,
        private readonly StepUpAuthenticationService $stepUp,
        private readonly UserAuditRecorder $audit,
    ) {}

    public function storeScope(Request $request, User $user): RedirectResponse
    {
        Gate::authorize('manageIdentity');

        $validated = $request->validate([
            'organization_id' => ['nullable', 'integer', 'min:1', 'required_without:facility_id', 'prohibits:facility_id'],
            'facility_id' => ['nullable', 'integer', 'min:1', 'required_without:organization_id'],
            'grant_reason' => ['required', 'string', 'min:10', 'max:500'],
            'valid_from' => ['nullable', 'date'],
            'valid_until' => ['nullable', 'date', 'after:valid_from'],
        ]);

        $this->stepUp->assertSatisfied($request, 'access_scope_changed');

        return $this->attempt($request, $user, 'access_scope_grant', function () use ($request, $user, $validated): void {
            $this->provisioning->grantScope(
                $request->user(),
                $user,
                isset($validated['organization_id']) ? (int) $validated['organization_id'] : null,
                isset($validated['facility_id']) ? (int) $validated['facility_id'] : null,
                (string) $validated['grant_reason'],
                isset($validated['valid_from']) ? CarbonImmutable::parse((string) $validated['valid_from']) : null,
                isset($validated['valid_until']) ? CarbonImmutable::parse((string) $validated['valid_until']) : null,
                $request,
            );
        }, 'Access scope granted.');
    }

    public function revokeScope(Request $request, User $user, UserAccessScope $scope): RedirectResponse
    {
        Gate::authorize('manageIdentity');

        $validated = $request->validate([
            'revocation_reason' => ['required', 'string', 'min:10', 'max:500'],
        ]);

        $this->stepUp->assertSatisfied($request, 'access_scope_changed');

        return $this->attempt($request, $user, 'access_scope_revoke', function () use ($request, $user, $scope, $validated): void {
            $this->provisioning->revokeScope(
                $request->user(),
                $user,
                $scope,
                (string) $validated['revocation_reason'],
                $request,
            );
        }, 'Access scope revoked.');
    }

    public function storeCapability(Request $request, User $user): RedirectResponse
    {
        Gate::authorize('managePrivileges');

        $validated = $request->validate([
            'capability' => ['required', Rule::in(array_column(Capability::cases(), 'value'))],
        ]);

        $this->stepUp->assertSatisfied($request, 'privilege_assignment_changed');

        return $this->attempt($request, $user, 'capability_grant', function () use ($request, $user, $validated): void {
            $this->provisioning->grantCapability(
                $request->user(),
                $user,
                Capability::from((string) $validated['capability']),
                $request,
            );
        }, 'Capability granted.');
    }

    public function revokeCapability(Request $request, User $user): RedirectResponse
    {
        Gate::authorize('managePrivileges');

        $validated = $request->validate([
            'capability' => ['required', Rule::in(array_column(Capability::cases(), 'value'))],
        ]);

        $this->stepUp->assertSatisfied($request, 'privilege_assignment_changed');

        return $this->attempt($request, $user, 'capability_revoke', function () use ($request, $user, $validated): void {
            $this->provisioning->revokeCapability(
                $request->user(),
                $user,
                Capability::from((string) $validated['capability']),
                $request,
            );
        }, 'Capability revoked.');
    }

    private function attempt(
        Request $request,
        User $user,
        string $operation,
        callable $mutation,
        string $successMessage,
    ): RedirectResponse {
        try {
            $mutation();
        } catch (AccountLifecycleViolation $exception) {
            $this->audit->record('administration.user.'.$operation.'_denied', 'authorization', 'denied', [
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

        return back()->with('message', $successMessage);
    }
}
