<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Audit\UserAuditRecorder;
use App\Services\Auth\AccountLifecycleViolation;
use App\Services\Auth\AccountSessionService;
use App\Services\Auth\BulkAccountDeactivationService;
use App\Services\Auth\StepUpAuthenticationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class UserBulkLifecycleController extends Controller
{
    private const EXECUTION_REASONS = [
        'bulk_account_deactivation',
        'workforce_offboarding',
        'security_incident_response',
        'access_review_remediation',
    ];

    private const REVOKE_REASONS = [
        'credential_hygiene',
        'suspected_compromise',
        'security_incident_response',
    ];

    public function __construct(
        private readonly BulkAccountDeactivationService $bulk,
        private readonly AccountSessionService $sessions,
        private readonly StepUpAuthenticationService $stepUp,
        private readonly UserAuditRecorder $audit,
    ) {}

    public function preview(Request $request): JsonResponse
    {
        Gate::authorize('manageIdentity');

        $validated = $request->validate([
            'user_ids' => ['required', 'array', 'min:1', 'max:'.BulkAccountDeactivationService::MAX_BATCH],
            'user_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
        ]);

        return response()->json($this->bulk->preview($request->user(), $validated['user_ids'], $request));
    }

    public function execute(Request $request): JsonResponse
    {
        Gate::authorize('manageIdentity');

        $validated = $request->validate([
            'user_ids' => ['required', 'array', 'min:1', 'max:'.BulkAccountDeactivationService::MAX_BATCH],
            'user_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
            'change_reason' => ['required', Rule::in(self::EXECUTION_REASONS)],
        ]);

        $this->stepUp->assertSatisfied($request, 'bulk_account_deactivation');

        try {
            $deactivated = $this->bulk->execute(
                $request->user(),
                $validated['user_ids'],
                (string) $validated['change_reason'],
                $request,
            );
        } catch (AccountLifecycleViolation $exception) {
            $this->audit->record('administration.user.bulk_deactivation_denied', 'authorization', 'denied', [
                'request' => $request,
                'reason' => $exception->reason,
                'http_status' => 422,
                'metadata' => ['member_count' => count($validated['user_ids'])],
            ]);

            throw ValidationException::withMessages([
                $exception->field => [$exception->getMessage()],
            ]);
        }

        return response()->json([
            'deactivated_user_ids' => $deactivated,
            'deactivated_count' => count($deactivated),
        ]);
    }

    public function revokeAccess(Request $request, User $user): RedirectResponse
    {
        Gate::authorize('manageIdentity');

        $validated = $request->validate([
            'change_reason' => ['required', Rule::in(self::REVOKE_REASONS)],
        ]);

        if ((bool) $user->is_protected) {
            $this->audit->record('administration.user.revoke_access_denied', 'authorization', 'denied', [
                'request' => $request,
                'reason' => 'protected_account',
                'target_type' => 'user',
                'target_id' => $user->getKey(),
                'http_status' => $request->expectsJson() ? 422 : 302,
            ]);

            throw ValidationException::withMessages([
                'user' => ['Protected-account credentials are revoked only through the sealed break-glass procedure.'],
            ]);
        }

        $result = $this->sessions->revoke(
            $user,
            $request,
            (string) $validated['change_reason'],
            keepCurrentSession: $request->user()->is($user),
        );

        return back()->with(
            'message',
            sprintf(
                'Revoked %d API token(s) and %d browser session(s).',
                $result['api_tokens_revoked'],
                $result['database_sessions_revoked'],
            ),
        );
    }
}
