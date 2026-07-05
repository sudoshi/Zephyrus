<?php

namespace App\Services\Deployment;

use App\Casts\PgTextArray;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Projects the config-authored service-line registry and taxonomy vocabulary into
 * the hosp_ref.* tables (Layer 2). Config authors, DB projects.
 *
 * Idempotent: every write is an upsert keyed on the natural key, so re-running
 * leaves row counts stable (same discipline as ModelCatalogImporter and the Summit
 * seeders). Array columns are bound with an explicit `?::text[]` cast to avoid
 * driver-dependent text->text[] coercion.
 *
 * Plan: docs/superpowers/plans/2026-07-04-service-line-location-deployment-implementation.md (§4.1, Phase 0)
 */
class ServiceLineRegistrar
{
    /**
     * Seed every registry table from config. Returns per-table upsert counts.
     *
     * @return array{vocab:int, service_lines:int, programs:int, capability_tags:int}
     */
    public function seed(): array
    {
        return [
            'vocab' => $this->seedVocab(),
            'service_lines' => $this->seedServiceLines(),
            'programs' => $this->seedPrograms(),
            'capability_tags' => $this->seedCapabilityTags(),
        ];
    }

    public function seedVocab(): int
    {
        $vocab = $this->config('taxonomy-vocab');
        $count = 0;

        foreach ($vocab['capability_levels'] ?? [] as $row) {
            DB::statement(
                'INSERT INTO hosp_ref.capability_levels (code, display_name, rank) VALUES (?, ?, ?)
                 ON CONFLICT (code) DO UPDATE SET display_name = EXCLUDED.display_name, rank = EXCLUDED.rank',
                [$row['code'], $row['name'], (int) $row['rank']]
            );
            $count++;
        }

        foreach ($vocab['idn_roles'] ?? [] as $row) {
            DB::statement(
                'INSERT INTO hosp_ref.idn_roles (code, display_name, sort_order) VALUES (?, ?, ?)
                 ON CONFLICT (code) DO UPDATE SET display_name = EXCLUDED.display_name, sort_order = EXCLUDED.sort_order',
                [$row['code'], $row['name'], (int) $row['sort']]
            );
            $count++;
        }

        foreach ($vocab['location_roles'] ?? [] as $row) {
            DB::statement(
                'INSERT INTO hosp_ref.location_roles (code, display_name, sort_order) VALUES (?, ?, ?)
                 ON CONFLICT (code) DO UPDATE SET display_name = EXCLUDED.display_name, sort_order = EXCLUDED.sort_order',
                [$row['code'], $row['name'], (int) $row['sort']]
            );
            $count++;
        }

        foreach ($vocab['evidence_classes'] ?? [] as $row) {
            DB::statement(
                'INSERT INTO hosp_ref.evidence_classes (code, display_name, is_regulated) VALUES (?, ?, ?)
                 ON CONFLICT (code) DO UPDATE SET display_name = EXCLUDED.display_name, is_regulated = EXCLUDED.is_regulated',
                [$row['code'], $row['name'], (bool) $row['regulated']]
            );
            $count++;
        }

        return $count;
    }

    public function seedServiceLines(): int
    {
        $config = $this->config('service-lines');
        $requiresMap = $config['requires_map'] ?? [];
        $count = 0;

        $sql = <<<'SQL'
            INSERT INTO hosp_ref.service_lines (
                service_line_code, display_name, clinical_domain, adult_or_pediatric, care_setting_default,
                requires_24_7, requires_inpatient_beds, requires_procedure_platform, requires_imaging,
                requires_lab, requires_pharmacy, requires_transport, requires_transfer_agreements,
                certification_or_designation, default_location_roles, default_workflow, aliases, sort_order, updated_at
            ) VALUES (
                ?, ?, ?, ?, ?,
                ?, ?, ?, ?,
                ?, ?, ?, ?,
                ?::text[], ?::text[], ?, ?::text[], ?, now()
            )
            ON CONFLICT (service_line_code) DO UPDATE SET
                display_name = EXCLUDED.display_name,
                clinical_domain = EXCLUDED.clinical_domain,
                adult_or_pediatric = EXCLUDED.adult_or_pediatric,
                care_setting_default = EXCLUDED.care_setting_default,
                requires_24_7 = EXCLUDED.requires_24_7,
                requires_inpatient_beds = EXCLUDED.requires_inpatient_beds,
                requires_procedure_platform = EXCLUDED.requires_procedure_platform,
                requires_imaging = EXCLUDED.requires_imaging,
                requires_lab = EXCLUDED.requires_lab,
                requires_pharmacy = EXCLUDED.requires_pharmacy,
                requires_transport = EXCLUDED.requires_transport,
                requires_transfer_agreements = EXCLUDED.requires_transfer_agreements,
                certification_or_designation = EXCLUDED.certification_or_designation,
                default_location_roles = EXCLUDED.default_location_roles,
                default_workflow = EXCLUDED.default_workflow,
                aliases = EXCLUDED.aliases,
                sort_order = EXCLUDED.sort_order,
                updated_at = now()
            SQL;

        foreach ($config['service_lines'] ?? [] as $code => $row) {
            $requires = $row['requires'] ?? [];
            $flag = static fn (string $token): bool => in_array($token, $requires, true);

            DB::statement($sql, [
                $code,
                $row['name'],
                $row['domain'],
                $row['population'] ?? 'adult',
                $row['setting'] ?? 'inpatient',
                $flag('24_7'),
                $flag('inpatient_beds'),
                $flag('procedure_platform'),
                $flag('imaging'),
                $flag('lab'),
                $flag('pharmacy'),
                $flag('transport'),
                $flag('transfer_agreements'),
                PgTextArray::literal($row['designations'] ?? []),
                PgTextArray::literal($row['location_roles'] ?? []),
                $row['workflow'] ?? null,
                PgTextArray::literal($row['aliases'] ?? []),
                (int) ($row['sort'] ?? 100),
            ]);
            $count++;

            // Guard against a token that does not map to a real column (typo in config).
            foreach ($requires as $token) {
                if (! array_key_exists($token, $requiresMap)) {
                    throw new RuntimeException("Unknown requires token '{$token}' for service line '{$code}'.");
                }
            }
        }

        return $count;
    }

    public function seedPrograms(): int
    {
        $config = $this->config('programs');
        $count = 0;

        $sql = <<<'SQL'
            INSERT INTO hosp_ref.programs (
                program_code, service_line_code, display_name, designation_type,
                designation_body, capability_level_implied, adult_or_pediatric, updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, now())
            ON CONFLICT (program_code) DO UPDATE SET
                service_line_code = EXCLUDED.service_line_code,
                display_name = EXCLUDED.display_name,
                designation_type = EXCLUDED.designation_type,
                designation_body = EXCLUDED.designation_body,
                capability_level_implied = EXCLUDED.capability_level_implied,
                adult_or_pediatric = EXCLUDED.adult_or_pediatric,
                updated_at = now()
            SQL;

        foreach ($config['programs'] ?? [] as $programCode => $row) {
            DB::statement($sql, [
                $programCode,
                $row['service_line'],
                $row['name'],
                $row['type'] ?? null,
                $row['body'] ?? null,
                $row['implies'] ?? null,
                $row['population'] ?? 'adult',
            ]);
            $count++;
        }

        return $count;
    }

    public function seedCapabilityTags(): int
    {
        $config = $this->config('capability-tags');
        $count = 0;

        $sql = <<<'SQL'
            INSERT INTO hosp_ref.capability_tags (
                tag_code, tag_category, display_name, description, applies_to, updated_at
            ) VALUES (?, ?, ?, ?, ?::text[], now())
            ON CONFLICT (tag_code) DO UPDATE SET
                tag_category = EXCLUDED.tag_category,
                display_name = EXCLUDED.display_name,
                description = EXCLUDED.description,
                applies_to = EXCLUDED.applies_to,
                updated_at = now()
            SQL;

        foreach ($config['capability_tags'] ?? [] as $tagCode => $row) {
            DB::statement($sql, [
                $tagCode,
                $row['category'],
                $row['name'],
                $row['description'] ?? null,
                PgTextArray::literal($row['applies_to'] ?? ['bed', 'room', 'facility_space']),
            ]);
            $count++;
        }

        return $count;
    }

    /**
     * Load a config/hospital/*.php authoring file by base name.
     *
     * @return array<string, mixed>
     */
    private function config(string $name): array
    {
        $path = base_path("config/hospital/{$name}.php");

        if (! is_file($path)) {
            throw new RuntimeException("Registry config file not found: {$path}");
        }

        return require $path;
    }
}
