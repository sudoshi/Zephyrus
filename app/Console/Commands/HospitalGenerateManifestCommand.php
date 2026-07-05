<?php

namespace App\Console\Commands;

use App\Services\Deployment\ManifestGenerator;
use Illuminate\Console\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * Generate a hospital manifest (config/hospital/hospital-1.php shape) for a facility_key
 * from the deployment tables, so a new facility is deployed from its own capability
 * matrix + facility-space import instead of a Summit copy (Phase 5).
 *
 *   php artisan hospital:generate-manifest SUMMIT_REGIONAL --write=/dev/stdout
 *   php artisan hospital:generate-manifest GEISINGER_GMC --write=config/hospital/geisinger-gmc.php
 *
 * After writing, register the facility_key -> path in config/hospital.php so
 * HospitalManifest::forFacility() can load it.
 *
 * Plan: docs/superpowers/plans/2026-07-04-service-line-location-deployment-implementation.md (Phase 5)
 */
class HospitalGenerateManifestCommand extends Command
{
    protected $signature = 'hospital:generate-manifest {facilityKey : hosp_org.facilities.facility_key} {--write= : Path (or /dev/stdout) to write the PHP manifest; omit to print a summary}';

    protected $description = 'Generate a per-facility hospital manifest from the deployment tables (hosp_org + hosp_ref + hosp_space + prod).';

    public function handle(ManifestGenerator $generator): int
    {
        $facilityKey = (string) $this->argument('facilityKey');

        try {
            $manifest = $generator->generate($facilityKey);
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $write = $this->option('write');

        if (! is_string($write) || $write === '') {
            $this->summarize($facilityKey, $manifest);

            return self::SUCCESS;
        }

        $php = $this->renderPhp($facilityKey, $manifest);

        if (in_array($write, ['/dev/stdout', 'php://stdout', '-'], true)) {
            // Write to the raw underlying output (not the SymfonyStyle wrapper, which
            // reformats/suppresses) with OUTPUT_RAW so the PHP's `<?php`, `<`, `>` are
            // not treated as console tags.
            $this->output->getOutput()->writeln($php, OutputInterface::OUTPUT_RAW);

            return self::SUCCESS;
        }

        $path = str_starts_with($write, '/') ? $write : base_path($write);

        if (file_put_contents($path, $php) === false) {
            $this->error("Could not write manifest to {$path}.");

            return self::FAILURE;
        }

        $this->info("Wrote manifest for {$facilityKey} to {$path}.");
        $this->line('Register it in config/hospital.php: '."'{$facilityKey}' => '".ltrim(str_replace(base_path().'/', '', $path), '/')."',");

        return self::SUCCESS;
    }

    /**
     * @param  array<string,mixed>  $manifest
     */
    private function summarize(string $facilityKey, array $manifest): void
    {
        $facility = $manifest['facility'] ?? [];
        $this->info("Manifest for {$facilityKey}: ".($facility['name'] ?? $facilityKey));
        $this->table(['Block', 'Count'], [
            ['network_facilities', count($manifest['network_facilities'] ?? [])],
            ['service_lines', count($manifest['service_lines'] ?? [])],
            ['units', count($manifest['units'] ?? [])],
            ['census_demo_targets', count($manifest['census_demo_targets'] ?? [])],
        ]);
        $this->line('Use --write=/dev/stdout to see the full PHP, or --write=config/hospital/<key>.php to save it.');
    }

    /**
     * @param  array<string,mixed>  $manifest
     */
    private function renderPhp(string $facilityKey, array $manifest): string
    {
        $header = <<<PHP
        <?php

        /**
         * Generated hospital manifest for {$facilityKey}.
         *
         * Produced by `php artisan hospital:generate-manifest {$facilityKey}` from the
         * deployment tables (hosp_org + hosp_ref + hosp_space + prod). Regenerate rather
         * than hand-editing structural blocks; tune demo-only blocks (providers, nurses,
         * transport vendors, census targets) per client after generation.
         */

        return
        PHP;

        return $header.' '.$this->export($manifest, 0).";\n";
    }

    /**
     * Render a value as PHP using short-array syntax (matches config/hospital/*.php).
     */
    private function export(mixed $value, int $depth): string
    {
        if (is_array($value)) {
            if ($value === []) {
                return '[]';
            }

            $isList = array_is_list($value);
            $pad = str_repeat('    ', $depth + 1);
            $close = str_repeat('    ', $depth);
            $lines = [];

            foreach ($value as $key => $item) {
                $prefix = $isList ? '' : $this->export($key, 0).' => ';
                $lines[] = $pad.$prefix.$this->export($item, $depth + 1).',';
            }

            return "[\n".implode("\n", $lines)."\n".$close.']';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if ($value === null) {
            return 'null';
        }

        if (is_int($value)) {
            return (string) $value;
        }

        if (is_float($value)) {
            return var_export($value, true);
        }

        return var_export((string) $value, true);
    }
}
