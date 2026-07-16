<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Audit\UserAuditRecorder;
use App\Services\Auth\AccountLifecycleViolation;
use App\Services\Auth\IdentityPurgeService;
use App\Services\Governance\GovernanceViolation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class IdentityPurgeController extends Controller
{
    public function __construct(
        private readonly IdentityPurgeService $purges,
        private readonly UserAuditRecorder $audit,
    ) {}

    public function store(Request $request, User $user): RedirectResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:10', 'max:500'],
        ]);

        try {
            $change = $this->purges->requestPurge($request, $user, (string) $validated['reason']);
        } catch (AccountLifecycleViolation|GovernanceViolation $exception) {
            $this->deny($request, $user, 'request', $exception);
        }

        return back()->with('message', 'Identity purge request '.$change->getKey().' awaits independent approval.');
    }

    public function decide(Request $request, string $changeRequestUuid): RedirectResponse
    {
        $validated = $request->validate([
            'decision' => ['required', 'in:approved,rejected'],
            'reason' => ['required', 'string', 'min:10', 'max:500'],
        ]);

        try {
            $this->purges->decide(
                $request,
                $changeRequestUuid,
                $validated['decision'] === 'approved',
                (string) $validated['reason'],
            );
        } catch (AccountLifecycleViolation|GovernanceViolation $exception) {
            $this->deny($request, null, 'decision', $exception, $changeRequestUuid);
        }

        return back()->with('message', 'Identity purge decision recorded immutably.');
    }

    public function execute(Request $request, User $user, string $changeRequestUuid): RedirectResponse
    {
        try {
            $this->purges->execute($request, $changeRequestUuid, $user);
        } catch (AccountLifecycleViolation|GovernanceViolation $exception) {
            $this->deny($request, $user, 'execution', $exception, $changeRequestUuid);
        }

        return redirect()->route('users.index')->with('message', 'The approved identity purge completed.');
    }

    private function deny(
        Request $request,
        ?User $target,
        string $operation,
        AccountLifecycleViolation|GovernanceViolation $exception,
        ?string $changeRequestUuid = null,
    ): never {
        $this->audit->record('administration.user.identity_purge_'.$operation.'_denied', 'authorization', 'denied', [
            'request' => $request,
            'reason' => $exception->reason,
            'target_type' => $target === null ? 'governed_change' : 'user',
            'target_id' => $target?->getKey() ?? $changeRequestUuid,
            'http_status' => $request->expectsJson() ? 422 : 302,
        ]);

        throw ValidationException::withMessages([
            $exception instanceof AccountLifecycleViolation ? $exception->field : 'governance' => [
                $exception->getMessage(),
            ],
        ]);
    }
}
