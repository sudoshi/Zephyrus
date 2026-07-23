<?php

use App\Traits\SafeMigration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    use SafeMigration;

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
CREATE TABLE IF NOT EXISTS prod.mobile_token_sessions (
    mobile_token_session_id bigserial PRIMARY KEY,
    session_uuid            uuid NOT NULL UNIQUE,
    user_id                 bigint NOT NULL
                            REFERENCES prod.users(id)
                            ON DELETE RESTRICT,
    token_family_uuid       uuid NOT NULL UNIQUE,
    refresh_token_id        bigint,
    status                  text NOT NULL DEFAULT 'active'
                            CHECK (status IN ('active', 'revoked', 'expired')),
    expires_at              timestamptz NOT NULL,
    revoked_at              timestamptz,
    revocation_reason       varchar(120),
    created_at              timestamptz NOT NULL DEFAULT now(),
    updated_at              timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT mobile_token_sessions_expiry_check
        CHECK (expires_at > created_at),
    CONSTRAINT mobile_token_sessions_revocation_check CHECK (
        (status = 'active' AND revoked_at IS NULL AND revocation_reason IS NULL)
        OR (status <> 'active' AND revoked_at IS NOT NULL AND revocation_reason IS NOT NULL)
    )
);

CREATE INDEX IF NOT EXISTS idx_mobile_token_sessions_user_active
    ON prod.mobile_token_sessions(user_id, status, expires_at);
CREATE UNIQUE INDEX IF NOT EXISTS uq_mobile_token_sessions_current_refresh
    ON prod.mobile_token_sessions(refresh_token_id)
    WHERE refresh_token_id IS NOT NULL;

COMMENT ON TABLE prod.mobile_token_sessions IS
    'Staff Hummingbird token-family lifecycle. Bearer values remain one-way hashed in Sanctum and are never stored here.';
COMMENT ON COLUMN prod.mobile_token_sessions.refresh_token_id IS
    'Identifier of the current refresh generation. Rotated predecessor hashes remain in Sanctum until family revocation or expiry so reuse can be detected.';
SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $this->safeDropIfExists('prod.mobile_token_sessions');
    }
};
