<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! $this->schemaTableExists('ops', 'actions')) {
            return;
        }

        Schema::table('ops.actions', function (Blueprint $table) {
            if (! Schema::hasColumn('ops.actions', 'owner_name')) {
                $table->string('owner_name', 160)->nullable()->after('status');
            }

            if (! Schema::hasColumn('ops.actions', 'assigned_to_user_id')) {
                $table->unsignedBigInteger('assigned_to_user_id')->nullable()->after('owner_name');
            }

            if (! Schema::hasColumn('ops.actions', 'assigned_at')) {
                $table->timestamp('assigned_at')->nullable()->after('assigned_to_user_id');
            }

            if (! Schema::hasColumn('ops.actions', 'due_at')) {
                $table->timestamp('due_at')->nullable()->after('assigned_at');
            }

            if (! Schema::hasColumn('ops.actions', 'expires_at')) {
                $table->timestamp('expires_at')->nullable()->after('due_at');
            }

            if (! Schema::hasColumn('ops.actions', 'completed_at')) {
                $table->timestamp('completed_at')->nullable()->after('executed_at');
            }

            if (! Schema::hasColumn('ops.actions', 'expired_at')) {
                $table->timestamp('expired_at')->nullable()->after('completed_at');
            }

            if (! Schema::hasColumn('ops.actions', 'overridden_at')) {
                $table->timestamp('overridden_at')->nullable()->after('expired_at');
            }

            if (! Schema::hasColumn('ops.actions', 'completion_payload')) {
                $table->jsonb('completion_payload')->default(DB::raw("'{}'::jsonb"))->after('payload');
            }

            if (! Schema::hasColumn('ops.actions', 'override_reason')) {
                $table->text('override_reason')->nullable()->after('completion_payload');
            }
        });

        DB::statement('CREATE INDEX IF NOT EXISTS ops_actions_lifecycle_idx ON ops.actions (status, due_at, expires_at)');
        DB::statement('CREATE INDEX IF NOT EXISTS ops_actions_owner_idx ON ops.actions (owner_name, status)');
    }

    public function down(): void
    {
        if (! $this->schemaTableExists('ops', 'actions')) {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS ops.ops_actions_owner_idx');
        DB::statement('DROP INDEX IF EXISTS ops.ops_actions_lifecycle_idx');

        Schema::table('ops.actions', function (Blueprint $table) {
            foreach ([
                'override_reason',
                'completion_payload',
                'overridden_at',
                'expired_at',
                'completed_at',
                'expires_at',
                'due_at',
                'assigned_at',
                'assigned_to_user_id',
                'owner_name',
            ] as $column) {
                if (Schema::hasColumn('ops.actions', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }

    private function schemaTableExists(string $schema, string $table): bool
    {
        $row = DB::selectOne(
            'SELECT to_regclass(?) IS NOT NULL AS table_exists',
            ["{$schema}.{$table}"]
        );

        return (bool) ($row?->table_exists ?? false);
    }
};
