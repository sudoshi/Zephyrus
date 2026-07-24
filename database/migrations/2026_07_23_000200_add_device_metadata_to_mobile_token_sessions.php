<?php

use App\Traits\SafeMigration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    use SafeMigration;

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
ALTER TABLE prod.mobile_token_sessions
    ADD COLUMN IF NOT EXISTS access_token_id bigint,
    ADD COLUMN IF NOT EXISTS installation_uuid uuid,
    ADD COLUMN IF NOT EXISTS platform varchar(10),
    ADD COLUMN IF NOT EXISTS device_name varchar(120),
    ADD COLUMN IF NOT EXISTS app_version varchar(80),
    ADD COLUMN IF NOT EXISTS os_version varchar(80),
    ADD COLUMN IF NOT EXISTS environment varchar(40),
    ADD COLUMN IF NOT EXISTS last_seen_at timestamptz;

UPDATE prod.mobile_token_sessions
SET last_seen_at = COALESCE(last_seen_at, updated_at, created_at, now())
WHERE last_seen_at IS NULL;

-- Preserve exact-row binding for families issued before this column existed,
-- but fail closed if more than one live same-name access row makes provenance
-- ambiguous. Normal rotation deletes the predecessor access generation, so a
-- legitimate active family has exactly one candidate.
UPDATE prod.mobile_token_sessions AS session
SET access_token_id = candidate.access_token_id
FROM (
    SELECT
        family.mobile_token_session_id,
        MIN(token.id) AS access_token_id
    FROM prod.mobile_token_sessions AS family
    JOIN prod.personal_access_tokens AS token
      ON token.tokenable_type = 'App\Models\User'
     AND token.tokenable_id = family.user_id
     AND token.name = 'mobile-access:' || family.token_family_uuid::text
     AND (token.expires_at IS NULL OR token.expires_at > now())
    WHERE family.access_token_id IS NULL
      AND family.status = 'active'
      AND family.revoked_at IS NULL
      AND family.expires_at > now()
    GROUP BY family.mobile_token_session_id
    HAVING COUNT(*) = 1
) AS candidate
WHERE session.mobile_token_session_id = candidate.mobile_token_session_id;

ALTER TABLE prod.mobile_token_sessions
    ALTER COLUMN last_seen_at SET DEFAULT now(),
    ALTER COLUMN last_seen_at SET NOT NULL;

ALTER TABLE prod.mobile_token_sessions
    DROP CONSTRAINT IF EXISTS mobile_token_sessions_platform_check,
    ADD CONSTRAINT mobile_token_sessions_platform_check
        CHECK (platform IS NULL OR platform IN ('ios', 'android')),
    DROP CONSTRAINT IF EXISTS mobile_token_sessions_device_identity_check,
    ADD CONSTRAINT mobile_token_sessions_device_identity_check CHECK (
        (installation_uuid IS NULL AND platform IS NULL)
        OR (installation_uuid IS NOT NULL AND platform IS NOT NULL)
    );

CREATE INDEX IF NOT EXISTS idx_mobile_token_sessions_user_last_seen
    ON prod.mobile_token_sessions(user_id, last_seen_at DESC)
    WHERE status = 'active';

CREATE UNIQUE INDEX IF NOT EXISTS uq_mobile_token_sessions_current_access
    ON prod.mobile_token_sessions(access_token_id)
    WHERE access_token_id IS NOT NULL;

COMMENT ON COLUMN prod.mobile_token_sessions.access_token_id IS
    'Identifier of the exact current access generation. Session self-service requires this pointer to match the authenticated Sanctum row.';
COMMENT ON COLUMN prod.mobile_token_sessions.installation_uuid IS
    'App-install identifier supplied at token exchange. It is retained for lifecycle correlation and never returned by the session-management API.';
COMMENT ON COLUMN prod.mobile_token_sessions.environment IS
    'Server-owned Laravel environment at issuance; the client cannot select or override it.';
COMMENT ON COLUMN prod.mobile_token_sessions.last_seen_at IS
    'Rate-limited server observation of an authenticated request for this token family.';
SQL);

        $environment = trim(Str::limit((string) app()->environment(), 40, ''));
        DB::table('prod.mobile_token_sessions')
            ->whereNull('environment')
            ->update([
                'environment' => $environment !== '' ? $environment : 'unknown',
            ]);
        DB::statement(
            'ALTER TABLE prod.mobile_token_sessions ALTER COLUMN environment SET NOT NULL',
        );
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
DROP INDEX IF EXISTS prod.idx_mobile_token_sessions_user_last_seen;
DROP INDEX IF EXISTS prod.uq_mobile_token_sessions_current_access;
ALTER TABLE prod.mobile_token_sessions
    DROP CONSTRAINT IF EXISTS mobile_token_sessions_device_identity_check,
    DROP CONSTRAINT IF EXISTS mobile_token_sessions_platform_check,
    DROP COLUMN IF EXISTS last_seen_at,
    DROP COLUMN IF EXISTS environment,
    DROP COLUMN IF EXISTS os_version,
    DROP COLUMN IF EXISTS app_version,
    DROP COLUMN IF EXISTS device_name,
    DROP COLUMN IF EXISTS platform,
    DROP COLUMN IF EXISTS installation_uuid,
    DROP COLUMN IF EXISTS access_token_id;
SQL);
    }
};
