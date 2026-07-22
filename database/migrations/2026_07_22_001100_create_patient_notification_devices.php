<?php

use App\Traits\SafeMigration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    use SafeMigration;

    /**
     * Patient notification endpoints are a separate, revocable registry from
     * both staff mobile_devices and authentication sessions. Push tokens are
     * encrypted application values; the digest is the only lookup key.
     */
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
CREATE TABLE IF NOT EXISTS patient_experience.notification_devices (
    notification_device_id     bigserial PRIMARY KEY,
    device_uuid                uuid NOT NULL UNIQUE,
    principal_id               bigint NOT NULL
                               REFERENCES patient_experience.principals(principal_id)
                               ON DELETE RESTRICT,
    platform                   text NOT NULL CHECK (platform IN ('ios', 'android')),
    environment                text NOT NULL CHECK (environment IN ('sandbox', 'production')),
    installation_uuid          uuid NOT NULL,
    encrypted_push_token       text NOT NULL,
    encryption_key_version     varchar(80) NOT NULL,
    push_token_digest          varchar(128) NOT NULL,
    app_version                varchar(80),
    os_version                 varchar(80),
    locale                     varchar(35),
    status                     text NOT NULL DEFAULT 'active'
                               CHECK (status IN ('active', 'revoked', 'invalid')),
    last_seen_at               timestamptz NOT NULL DEFAULT now(),
    revoked_at                 timestamptz,
    revocation_reason          varchar(120),
    created_at                 timestamptz NOT NULL DEFAULT now(),
    updated_at                 timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT patient_notification_device_token_not_blank
        CHECK (btrim(encrypted_push_token) <> '' AND btrim(push_token_digest) <> ''),
    CONSTRAINT patient_notification_device_revocation_consistent
        CHECK ((status = 'active' AND revoked_at IS NULL AND revocation_reason IS NULL)
            OR (status <> 'active' AND revoked_at IS NOT NULL AND revocation_reason IS NOT NULL))
);

CREATE UNIQUE INDEX IF NOT EXISTS uq_patient_notification_devices_active_token
    ON patient_experience.notification_devices(push_token_digest)
    WHERE status = 'active';
CREATE UNIQUE INDEX IF NOT EXISTS uq_patient_notification_devices_active_installation
    ON patient_experience.notification_devices(principal_id, platform, environment, installation_uuid)
    WHERE status = 'active';
CREATE INDEX IF NOT EXISTS idx_patient_notification_devices_principal_active
    ON patient_experience.notification_devices(principal_id, last_seen_at DESC)
    WHERE status = 'active';

COMMENT ON TABLE patient_experience.notification_devices IS
    'Patient-realm push registration only. Tokens are encrypted and no clinical notification payload belongs in this table.';
SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $this->safeDropIfExists('patient_experience.notification_devices');
    }
};
