<?php

use App\Traits\SafeMigration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    use SafeMigration;

    private const ACTIVE_STATUSES = [
        'requested', 'accepted', 'queued', 'assigned', 'dispatched', 'arrived_pickup',
        'patient_ready', 'patient_not_ready', 'picked_up', 'en_route', 'arrived_destination',
        'handoff_started', 'handoff_complete', 'escalated',
    ];

    private const ASSIGNMENT_REQUIRED_STATUSES = [
        'assigned', 'dispatched', 'arrived_pickup', 'patient_ready', 'patient_not_ready',
        'picked_up', 'en_route', 'arrived_destination', 'handoff_started', 'handoff_complete',
    ];

    public function up(): void
    {
        Schema::create('prod.transport_resources', function (Blueprint $table): void {
            $table->id('transport_resource_id');
            $table->uuid('resource_uuid')->unique();
            $table->string('resource_key')->unique();
            $table->string('resource_type');
            $table->string('display_name');
            $table->unsignedInteger('capacity')->default(1);
            $table->unsignedBigInteger('actor_user_id')->nullable()->index();
            $table->jsonb('capabilities')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->string('source')->default('configured');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['resource_type', 'is_active']);
        });

        Schema::create('prod.transport_assignments', function (Blueprint $table): void {
            $table->id('transport_assignment_id');
            $table->uuid('assignment_uuid')->unique();
            $table->foreignId('transport_request_id')
                ->constrained('prod.transport_requests', 'transport_request_id')
                ->cascadeOnDelete();
            $table->foreignId('transport_resource_id')
                ->constrained('prod.transport_resources', 'transport_resource_id')
                ->restrictOnDelete();
            $table->unsignedInteger('capacity_units')->default(1);
            $table->string('status')->default('active');
            $table->timestampTz('reserved_from');
            $table->timestampTz('released_at')->nullable();
            $table->unsignedBigInteger('assigned_by_user_id')->nullable();
            $table->unsignedBigInteger('released_by_user_id')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->index(['transport_resource_id', 'status', 'released_at'], 'transport_assignments_resource_active_idx');
            $table->index(['transport_request_id', 'created_at'], 'transport_assignments_request_idx');
        });

        Schema::create('prod.transport_handoff_evidence', function (Blueprint $table): void {
            $table->id('transport_handoff_evidence_id');
            $table->uuid('evidence_uuid')->unique();
            $table->foreignId('transport_request_id')
                ->unique()
                ->constrained('prod.transport_requests', 'transport_request_id')
                ->cascadeOnDelete();
            $table->string('handoff_to');
            $table->string('receiver_role');
            $table->string('acceptance_status');
            $table->timestampTz('accepted_at');
            $table->text('handoff_summary')->nullable();
            $table->jsonb('documents')->nullable();
            $table->jsonb('outstanding_risks')->nullable();
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->timestampTz('created_at')->useCurrent();
        });

        Schema::create('prod.transport_commands', function (Blueprint $table): void {
            $table->id('transport_command_id');
            $table->uuid('command_uuid')->unique();
            $table->string('idempotency_key', 200)->unique();
            $table->foreignId('transport_request_id')
                ->nullable()
                ->constrained('prod.transport_requests', 'transport_request_id')
                ->cascadeOnDelete();
            $table->string('command_type');
            $table->string('request_hash', 64);
            $table->jsonb('response_payload');
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->string('source')->default('web');
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['transport_request_id', 'created_at']);
            $table->index(['command_type', 'created_at']);
        });

        Schema::table('prod.transport_requests', function (Blueprint $table): void {
            $table->boolean('handoff_required')->default(false);
            $table->string('escalated_from_status')->nullable();
            $table->unsignedBigInteger('lifecycle_version')->default(1);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE prod.transport_requests
            ADD COLUMN priority_rank smallint GENERATED ALWAYS AS (
                CASE priority WHEN 'stat' THEN 0 WHEN 'urgent' THEN 1 ELSE 2 END
            ) STORED
        SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE prod.transport_requests
            ADD COLUMN needed_at_sort timestamp GENERATED ALWAYS AS (
                COALESCE(needed_at, 'infinity'::timestamp)
            ) STORED
        SQL);

        DB::statement(<<<'SQL'
            UPDATE prod.transport_requests
            SET metadata = COALESCE(metadata, '{}'::jsonb) || jsonb_build_object(
                'transport_governance', jsonb_build_object(
                    'grandfathered', true,
                    'reason', 'pre_lifecycle_terminal_record'
                )
            )
            WHERE request_type IN ('inpatient','transfer','ems','care_transition')
              AND status IN ('completed','canceled','failed')
        SQL);
        DB::statement(<<<'SQL'
            UPDATE prod.transport_requests
            SET handoff_required = request_type IN ('inpatient','transfer','ems','care_transition')
                AND status NOT IN ('completed','canceled','failed')
        SQL);
        DB::statement(<<<'SQL'
            UPDATE prod.transport_requests
            SET escalated_from_status = CASE
                WHEN NULLIF(BTRIM(assigned_team), '') IS NOT NULL
                  OR NULLIF(BTRIM(assigned_vendor), '') IS NOT NULL THEN 'assigned'
                ELSE 'requested'
            END
            WHERE status = 'escalated'
        SQL);
        DB::statement(<<<'SQL'
            UPDATE prod.transport_requests tr
            SET lifecycle_version = GREATEST(1, event_counts.event_count)
            FROM (
                SELECT requests.transport_request_id, COUNT(events.transport_event_id)::bigint AS event_count
                FROM prod.transport_requests requests
                LEFT JOIN prod.transport_events events
                  ON events.transport_request_id = requests.transport_request_id
                GROUP BY requests.transport_request_id
            ) event_counts
            WHERE event_counts.transport_request_id = tr.transport_request_id
        SQL);

        DB::statement("ALTER TABLE prod.transport_resources ADD CONSTRAINT chk_transport_resource_type CHECK (resource_type IN ('transporter','team','vendor'))");
        DB::statement('ALTER TABLE prod.transport_resources ADD CONSTRAINT chk_transport_resource_capacity CHECK (capacity > 0)');
        DB::statement("ALTER TABLE prod.transport_assignments ADD CONSTRAINT chk_transport_assignment_status CHECK (status IN ('active','completed','canceled','failed','released'))");
        DB::statement('ALTER TABLE prod.transport_assignments ADD CONSTRAINT chk_transport_assignment_units CHECK (capacity_units > 0)');
        DB::statement("ALTER TABLE prod.transport_handoff_evidence ADD CONSTRAINT chk_transport_handoff_acceptance CHECK (acceptance_status IN ('accepted','accepted_with_risks'))");
        DB::statement('CREATE UNIQUE INDEX transport_assignments_one_active_request_idx ON prod.transport_assignments (transport_request_id) WHERE status = \'active\' AND released_at IS NULL');
        DB::statement('CREATE INDEX transport_requests_cursor_idx ON prod.transport_requests (priority_rank, needed_at_sort, transport_request_id DESC) WHERE is_deleted = false');

        $this->backfillActiveAssignments();

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION prod.reject_transport_ledger_mutation()
            RETURNS trigger AS $$
            BEGIN
                -- Direct ledger mutation and ordinary parent-row cascades are
                -- forbidden. The deterministic synthetic reset opts in for one
                -- transaction through a transaction-local custom setting.
                IF TG_OP = 'DELETE'
                   AND pg_trigger_depth() > 1
                   AND current_setting('zephyrus.allow_transport_ledger_reset', true) = 'on' THEN
                    RETURN OLD;
                END IF;
                RAISE EXCEPTION 'transport lifecycle ledgers are append-only';
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER transport_events_append_only
            BEFORE UPDATE OR DELETE ON prod.transport_events
            FOR EACH ROW EXECUTE FUNCTION prod.reject_transport_ledger_mutation();

            CREATE TRIGGER transport_commands_append_only
            BEFORE UPDATE OR DELETE ON prod.transport_commands
            FOR EACH ROW EXECUTE FUNCTION prod.reject_transport_ledger_mutation();

            CREATE TRIGGER transport_handoff_evidence_append_only
            BEFORE UPDATE OR DELETE ON prod.transport_handoff_evidence
            FOR EACH ROW EXECUTE FUNCTION prod.reject_transport_ledger_mutation();
        SQL);
    }

    public function down(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('Transport governance contains append-only production records and requires a forward-repair rollback plan.');
        }

        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS transport_events_append_only ON prod.transport_events;
            DROP TRIGGER IF EXISTS transport_commands_append_only ON prod.transport_commands;
            DROP TRIGGER IF EXISTS transport_handoff_evidence_append_only ON prod.transport_handoff_evidence;
            DROP FUNCTION IF EXISTS prod.reject_transport_ledger_mutation();
        SQL);

        DB::statement('DROP INDEX IF EXISTS prod.transport_requests_cursor_idx');
        DB::statement('DROP INDEX IF EXISTS prod.transport_assignments_one_active_request_idx');
        Schema::dropIfExists('prod.transport_commands');
        Schema::dropIfExists('prod.transport_handoff_evidence');
        Schema::dropIfExists('prod.transport_assignments');
        Schema::dropIfExists('prod.transport_resources');

        Schema::table('prod.transport_requests', function (Blueprint $table): void {
            $table->dropColumn(['handoff_required', 'escalated_from_status', 'lifecycle_version', 'priority_rank', 'needed_at_sort']);
        });
    }

    private function backfillActiveAssignments(): void
    {
        $rows = DB::table('prod.transport_requests')
            ->where('is_deleted', false)
            ->whereIn('status', self::ACTIVE_STATUSES)
            ->where(function ($query): void {
                $query->whereRaw("NULLIF(BTRIM(assigned_team), '') IS NOT NULL")
                    ->orWhereRaw("NULLIF(BTRIM(assigned_vendor), '') IS NOT NULL")
                    ->orWhereIn('status', self::ASSIGNMENT_REQUIRED_STATUSES);
            })
            ->orderBy('transport_request_id')
            ->get([
                'transport_request_id', 'assigned_team', 'assigned_vendor', 'assigned_at', 'requested_at',
            ]);

        $groups = $rows->groupBy(function (object $row): string {
            $vendor = trim((string) $row->assigned_vendor);
            $team = trim((string) $row->assigned_team);
            $type = $vendor !== '' ? 'vendor' : 'team';
            $name = $vendor !== '' ? $vendor : ($team !== '' ? $team : 'Legacy unresolved assignment');

            return $type.'|'.$name;
        });

        foreach ($groups as $groupKey => $requests) {
            [$type, $name] = explode('|', (string) $groupKey, 2);
            $resourceKey = 'legacy-'.$type.'-'.substr(hash('sha256', $name), 0, 32);
            $resourceId = DB::table('prod.transport_resources')->insertGetId([
                'resource_uuid' => (string) Str::uuid(),
                'resource_key' => $resourceKey,
                'resource_type' => $type,
                'display_name' => $name,
                'capacity' => max(1, $requests->count()),
                'capabilities' => json_encode([]),
                'metadata' => json_encode([
                    'backfilled' => true,
                    'unresolved_identity' => $name === 'Legacy unresolved assignment',
                ]),
                'source' => 'legacy-backfill',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ], 'transport_resource_id');

            foreach ($requests as $request) {
                DB::table('prod.transport_assignments')->insert([
                    'assignment_uuid' => (string) Str::uuid(),
                    'transport_request_id' => $request->transport_request_id,
                    'transport_resource_id' => $resourceId,
                    'capacity_units' => 1,
                    'status' => 'active',
                    'reserved_from' => $request->assigned_at ?? $request->requested_at ?? now(),
                    'metadata' => json_encode(['backfilled' => true]),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }
};
