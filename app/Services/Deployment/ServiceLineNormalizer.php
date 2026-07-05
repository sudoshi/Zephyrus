<?php

namespace App\Services\Deployment;

use App\Casts\PgTextArray;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Resolves any service-line code — canonical or legacy alias — to its canonical
 * form. Backs `deployment:normalize-service-lines` and any consumer that must fold
 * legacy Summit codes (trauma_surgery -> trauma_acute_care_surgery,
 * medicine -> hospital_medicine, cardiology -> cardiovascular) onto canonical.
 *
 * The alias index is built from the DB registry (hosp_ref.service_lines.aliases)
 * when seeded, and falls back to config/hospital/service-lines.php otherwise, so it
 * works before or after `deployment:seed-registry` has run. Cached statically;
 * call flush() after reseeding within a long-lived process.
 *
 * Plan: docs/superpowers/plans/2026-07-04-service-line-location-deployment-implementation.md (§5, Phase 0)
 */
class ServiceLineNormalizer
{
    /** @var array<string, string>|null */
    private static ?array $index = null;

    /**
     * Resolve a code (canonical or alias) to its canonical service_line_code.
     * Unknown codes are returned unchanged.
     */
    public function canonical(string $code): string
    {
        $code = trim($code);

        if ($code === '') {
            return $code;
        }

        return $this->index()[$code] ?? $code;
    }

    public function isKnown(string $code): bool
    {
        return isset($this->index()[trim($code)]);
    }

    /**
     * All canonical service_line_codes.
     *
     * @return list<string>
     */
    public function all(): array
    {
        return array_values(array_unique(array_values($this->index())));
    }

    /**
     * The full code -> canonical map (canonical codes map to themselves;
     * aliases map to their canonical).
     *
     * @return array<string, string>
     */
    public function index(): array
    {
        if (self::$index !== null) {
            return self::$index;
        }

        return self::$index = $this->fromDatabase() ?? $this->fromConfig();
    }

    public static function flush(): void
    {
        self::$index = null;
    }

    /**
     * @return array<string, string>|null
     */
    private function fromDatabase(): ?array
    {
        try {
            $rows = DB::table('hosp_ref.service_lines')
                ->select('service_line_code', 'aliases')
                ->get();
        } catch (Throwable) {
            return null; // table not migrated yet — fall back to config
        }

        if ($rows->isEmpty()) {
            return null; // not seeded yet — fall back to config
        }

        $map = [];

        foreach ($rows as $row) {
            $canonical = (string) $row->service_line_code;
            $map[$canonical] = $canonical;

            foreach (PgTextArray::parse($row->aliases) as $alias) {
                $map[$alias] = $canonical;
            }
        }

        return $map;
    }

    /**
     * @return array<string, string>
     */
    private function fromConfig(): array
    {
        $config = require base_path('config/hospital/service-lines.php');
        $map = [];

        foreach ($config['service_lines'] ?? [] as $code => $row) {
            $map[$code] = $code;

            foreach ($row['aliases'] ?? [] as $alias) {
                $map[$alias] = $code;
            }
        }

        return $map;
    }
}
