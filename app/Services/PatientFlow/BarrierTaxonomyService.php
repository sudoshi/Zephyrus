<?php

namespace App\Services\PatientFlow;

class BarrierTaxonomyService
{
    /**
     * @return array<string, mixed>
     */
    public function definition(string $code): array
    {
        $code = $this->normalizeCode($code);
        $definitions = config('patient_flow_barriers.barriers', []);
        $defaults = config('patient_flow_barriers.defaults', []);

        if (isset($definitions[$code]) && is_array($definitions[$code])) {
            return ['code' => $code, 'known' => true] + $definitions[$code] + $defaults;
        }

        return [
            'code' => $code,
            'known' => false,
            'label' => 'Unclassified barrier',
        ] + $defaults;
    }

    public function statusFor(string $code, int|float|null $minutesRemaining): string
    {
        if ($minutesRemaining === null) {
            return 'ok';
        }

        $definition = $this->definition($code);
        $delayAfter = (int) ($definition['delay_after_minutes'] ?? 0);
        $watchWithin = (int) ($definition['watch_within_minutes'] ?? 30);

        if ($minutesRemaining < $delayAfter) {
            return 'delayed';
        }

        return $minutesRemaining <= $watchWithin ? 'watch' : 'ok';
    }

    public function ownerFor(string $code): string
    {
        return (string) ($this->definition($code)['owner_role'] ?? 'bed_manager');
    }

    public function eddySummaryFor(string $code): string
    {
        return (string) ($this->definition($code)['eddy_summary'] ?? config('patient_flow_barriers.defaults.eddy_summary'));
    }

    /**
     * @return list<string>
     */
    public function rtdcMetricsFor(string $code): array
    {
        $metrics = $this->definition($code)['rtdc_metrics'] ?? [];

        return array_values(array_filter(array_map('strval', is_array($metrics) ? $metrics : [])));
    }

    private function normalizeCode(string $code): string
    {
        $code = strtolower(trim($code));
        $code = preg_replace('/[^a-z0-9_]+/', '_', $code) ?: '';

        return trim($code, '_') ?: 'unclassified_barrier';
    }
}
