<?php

namespace App\Services\Staffing;

use App\Casts\PgTextArray;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Phase 7: projects the config-authored staff-role taxonomy
 * (config/hospital/staff-roles.php) into hosp_ref.staff_roles. Config authors,
 * DB projects — the same idempotent-upsert discipline as ServiceLineRegistrar.
 *
 * Plan: docs/superpowers/plans/2026-07-04-staffing-alignment-wizard-implementation.md (§6 task 2)
 */
class StaffRoleRegistrar
{
    /**
     * Seed hosp_ref.staff_roles from config. Returns the number of roles upserted.
     */
    public function seed(): int
    {
        $config = $this->config();
        $count = 0;

        $sql = <<<'SQL'
            INSERT INTO hosp_ref.staff_roles (
                role_code, display_name, role_category, is_provider, is_nursing, is_clinical,
                is_regulated, default_workflow, default_app_permissions, sort_order, metadata, updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?::text[], ?, ?::jsonb, now())
            ON CONFLICT (role_code) DO UPDATE SET
                display_name = EXCLUDED.display_name,
                role_category = EXCLUDED.role_category,
                is_provider = EXCLUDED.is_provider,
                is_nursing = EXCLUDED.is_nursing,
                is_clinical = EXCLUDED.is_clinical,
                is_regulated = EXCLUDED.is_regulated,
                default_workflow = EXCLUDED.default_workflow,
                default_app_permissions = EXCLUDED.default_app_permissions,
                sort_order = EXCLUDED.sort_order,
                metadata = EXCLUDED.metadata,
                updated_at = now()
            SQL;

        foreach ($config['staff_roles'] ?? [] as $roleCode => $row) {
            $metadata = [];
            if (isset($row['app_role'])) {
                $metadata['app_role'] = $row['app_role'];
            }

            DB::statement($sql, [
                $roleCode,
                $row['name'],
                $row['category'],
                (bool) ($row['provider'] ?? false),
                (bool) ($row['nursing'] ?? false),
                (bool) ($row['clinical'] ?? true),
                (bool) ($row['regulated'] ?? false),
                $row['workflow'] ?? null,
                PgTextArray::literal($row['permissions'] ?? []),
                (int) ($row['sort'] ?? 100),
                json_encode($metadata),
            ]);
            $count++;
        }

        return $count;
    }

    /**
     * @return array<string, mixed>
     */
    private function config(): array
    {
        $path = base_path('config/hospital/staff-roles.php');

        if (! is_file($path)) {
            throw new RuntimeException("Staff-role config file not found: {$path}");
        }

        return require $path;
    }
}
