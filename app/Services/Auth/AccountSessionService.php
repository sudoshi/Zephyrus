<?php

namespace App\Services\Auth;

use App\Models\User;
use App\Services\Audit\UserAuditRecorder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AccountSessionService
{
    public const SESSION_VERSION_KEY = 'auth_session_version';

    public function __construct(private readonly UserAuditRecorder $audit) {}

    public function establish(Request $request, User $user): void
    {
        $request->session()->put([
            'user_id' => $user->getKey(),
            'username' => $user->username,
            self::SESSION_VERSION_KEY => (int) $user->auth_session_version,
        ]);
    }

    /**
     * Revoke every previously issued browser/API credential. When the actor is
     * changing their own password, keepCurrentSession rotates and rebinds only
     * the current browser session to the new generation.
     *
     * @return array{api_tokens_revoked: int, database_sessions_revoked: int, session_version: int}
     */
    public function revoke(
        User $user,
        Request $request,
        string $reason,
        bool $keepCurrentSession = false,
    ): array {
        $beforeVersion = (int) $user->auth_session_version;
        $hadRememberToken = is_string($user->getRememberToken()) && $user->getRememberToken() !== '';
        $apiTokens = $user->tokens()->count();
        $user->tokens()->delete();

        $databaseSessions = 0;
        if (Schema::hasTable('prod.sessions')) {
            $databaseSessions = DB::table('prod.sessions')->where('user_id', $user->getKey())->count();
            DB::table('prod.sessions')->where('user_id', $user->getKey())->delete();
        }

        $user->forceFill([
            'auth_session_version' => $beforeVersion + 1,
            'remember_token' => null,
        ])->save();

        if ($keepCurrentSession && $request->user()?->is($user)) {
            $request->session()->migrate(true);
            $this->establish($request, $user);
        }

        $this->audit->record('security.user.access_revoked', 'security', 'success', [
            'request' => $request,
            'reason' => $reason,
            'target_type' => 'user',
            'target_id' => $user->getKey(),
            'changes' => [
                'auth_session_version' => ['from' => $beforeVersion, 'to' => (int) $user->auth_session_version],
                'remember_token_invalidated' => ['from' => $hadRememberToken, 'to' => true],
                'api_tokens_revoked' => ['from' => $apiTokens, 'to' => 0],
                'database_sessions_revoked' => ['from' => $databaseSessions, 'to' => 0],
            ],
        ]);

        return [
            'api_tokens_revoked' => $apiTokens,
            'database_sessions_revoked' => $databaseSessions,
            'session_version' => (int) $user->auth_session_version,
        ];
    }
}
