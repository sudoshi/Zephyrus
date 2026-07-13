<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Auth\UserExternalIdentity;
use App\Models\User;
use App\Services\Audit\UserAuditRecorder;
use App\Services\Auth\AccountLifecycleViolation;
use App\Services\Auth\Oidc\ExternalIdentityLifecycleService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class ExternalIdentityController extends Controller
{
    public function __construct(
        private readonly ExternalIdentityLifecycleService $lifecycle,
        private readonly UserAuditRecorder $audit,
    ) {}

    public function unlink(Request $request, User $user, UserExternalIdentity $identity): RedirectResponse
    {
        return $this->mutate($request, $user, $identity, 'unlink');
    }

    public function relink(Request $request, User $user, UserExternalIdentity $identity): RedirectResponse
    {
        return $this->mutate($request, $user, $identity, 'relink');
    }

    private function mutate(
        Request $request,
        User $user,
        UserExternalIdentity $identity,
        string $operation,
    ): RedirectResponse {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:10', 'max:500'],
        ]);

        try {
            $this->lifecycle->{$operation}($request, $user, $identity, (string) $validated['reason']);
        } catch (AccountLifecycleViolation $exception) {
            $this->audit->record('administration.external_identity.'.$operation.'_denied', 'authorization', 'denied', [
                'request' => $request,
                'reason' => $exception->reason,
                'target_type' => 'user_external_identity',
                'target_id' => $identity->getKey(),
                'http_status' => $request->expectsJson() ? 422 : 302,
            ]);

            throw ValidationException::withMessages([
                $exception->field => [$exception->getMessage()],
            ]);
        }

        return back()->with(
            'message',
            $operation === 'unlink' ? 'External identity unlinked.' : 'External identity relinked.',
        );
    }
}
