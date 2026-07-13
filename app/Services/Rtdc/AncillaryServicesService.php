<?php

namespace App\Services\Rtdc;

use Illuminate\Support\Facades\DB;

/**
 * Builds the Ancillary Services page payload (resources/js/Pages/RTDC/AncillaryServices.jsx).
 *
 * The page previously generated its grid client-side with Math.random and
 * re-rolled every 30s (flickering, non-deterministic, not backed by anything).
 * This service produces the SAME shape — one entry per real seeded unit, each
 * with a per-service { value, trend[] } map — but computed deterministically
 * from the unit id + service so the demo is stable across refreshes and tied to
 * the real ward list (prod.units).
 *
 * Returned shape (matches generateDemoData() in resources/js/mock-data/rtdc.js):
 *   list<{ id:int, name:string, services: array<string, {value:int, trend: list<{time:string,value:int}>}|null> }>
 *
 * Ancillary wait-times have no upstream source system in this demo, so the
 * values are a deterministic synthesis (not random) rather than a real feed —
 * suitable for demonstrating the surface; a real ingestion can replace build()
 * later without changing the payload contract.
 */
class AncillaryServicesService
{
    /** Service catalog mirrored from the frontend (id => category). */
    private const SERVICES = [
        'stress' => 'imaging',
        'echo' => 'imaging',
        'ct_mri' => 'imaging',
        'diagnostic' => 'imaging',
        'ir' => 'imaging',
        'pt_ot' => 'therapy',
        'respiratory' => 'therapy',
        'dialysis' => 'therapy',
        'pharmacy' => 'support',
        'lab' => 'support',
        'snf' => 'support',
        'palliative' => 'support',
        'infectious' => 'support',
        'diabetes' => 'support',
        'education' => 'support',
        'dme' => 'support',
    ];

    /** Base wait-time window (minutes) per category: [min, span]. */
    private const CATEGORY_BASE = [
        'imaging' => [30, 60],
        'therapy' => [30, 90],
        'support' => [30, 120],
    ];

    /** @return list<array<string,mixed>> */
    public function build(): array
    {
        $units = DB::table('prod.units')
            ->where('is_deleted', false)
            ->orderBy('unit_id')
            ->get(['unit_id', 'name']);

        $nowSec = now()->startOfHour()->getTimestamp();

        return $units->map(function ($unit) use ($nowSec): array {
            $services = [];
            foreach (self::SERVICES as $serviceId => $category) {
                // ~80% presence, deterministic per (unit, service).
                if ($this->hash($unit->unit_id.$serviceId.'present') % 5 === 0) {
                    $services[$serviceId] = null;

                    continue;
                }

                $value = $this->waitTime((int) $unit->unit_id, $serviceId, $category);
                $services[$serviceId] = [
                    'value' => $value,
                    'trend' => $this->trend((int) $unit->unit_id, $serviceId, $value, $nowSec),
                    // The cross-department RTDC page remains the consumer view;
                    // Imaging and Laboratory tiles hand off to their owned
                    // workspaces with the unit and provenance preserved.
                    'drillHref' => match (true) {
                        $category === 'imaging' => '/radiology/worklist?'.http_build_query([
                            'unitId' => (int) $unit->unit_id,
                            'source' => 'ancillary_services',
                        ]),
                        $serviceId === 'lab' => '/lab?'.http_build_query([
                            'unitId' => (int) $unit->unit_id,
                            'source' => 'ancillary_services',
                        ]),
                        default => null,
                    },
                ];
            }

            return [
                'id' => (int) $unit->unit_id,
                'name' => $unit->name,
                'services' => $services,
            ];
        })->all();
    }

    /** Deterministic wait time (minutes) within the category window. */
    private function waitTime(int $unitId, string $serviceId, string $category): int
    {
        [$min, $span] = self::CATEGORY_BASE[$category] ?? [30, 60];
        $h = $this->hash($unitId.$serviceId.'value');

        return (int) ($min + ($h % ($span + 1)));
    }

    /**
     * 12-hour deterministic trend wave around the current value.
     *
     * @return list<array{time:string,value:int}>
     */
    private function trend(int $unitId, string $serviceId, int $value, int $nowSec): array
    {
        $data = [];
        for ($i = 0; $i < 12; $i++) {
            $h = $this->hash($unitId.$serviceId.'trend'.$i);
            // ±15% deterministic variation.
            $delta = ($h % 31 - 15) / 100.0;
            $point = max(1, (int) round($value * (1 + $delta)));
            $data[] = [
                'time' => date('c', $nowSec - (12 - $i) * 3600),
                'value' => $point,
            ];
        }

        return $data;
    }

    private function hash(string $key): int
    {
        return abs(crc32($key));
    }
}
