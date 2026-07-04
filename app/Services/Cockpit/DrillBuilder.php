<?php

namespace App\Services\Cockpit;

use App\Enums\CockpitStatus;
use App\Services\Evs\EvsOperationsService;
use App\Services\Operations\RoomStatusService;
use App\Services\Staffing\StaffingOperationsService;
use App\Services\Transport\TransportOperationsService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Builds the per-domain drill payload (spec §3.3) in the §6.4 Cell grammar
 * that Components/cockpit/DataTable.tsx renders — React stays purely
 * presentational.
 *
 * Single-snapshot discipline: KPI tiles come from the CACHED cockpit
 * snapshot (the same numbers on the wall), never a second computation; only
 * the detail tables read the live domain boards. A domain hidden by
 * COCKPIT_HIDE_DEMO_DOMAINS has no drill (D5). Cell status tokens are the
 * CANON vocabulary ('critical'/'warning'/…) per the frozen cockpit.ts
 * cellSchema — the logical→canon bridge happens here via CockpitStatus.
 *
 * The CommandCenterDrilldownService timeline/opportunities deep detail wires
 * into the modal in P3; each payload carries drilldownHref for that seam.
 */
class DrillBuilder
{
    public const DOMAINS = ['rtdc', 'ed', 'periop', 'staffing', 'flow', 'quality', 'service', 'financial', 'okr'];

    private const TITLES = [
        'rtdc' => 'Real-Time Demand & Capacity — Unit Capacity Board',
        'ed' => 'Emergency Department — Track Board',
        'periop' => 'Perioperative — OR Room Board',
        'staffing' => 'Staffing & Workforce — Unit Coverage',
        'flow' => 'Patient Flow & Transport',
        'quality' => 'Quality & Safety — HAI / Safety Ledger',
        'service' => 'Service Lines — Throughput',
        'financial' => 'Financial Stewardship',
        'okr' => 'Executive OKR Scorecard',
    ];

    public function __construct(
        private readonly SnapshotBuilder $snapshots,
        private readonly RoomStatusService $rooms,
        private readonly StaffingOperationsService $staffing,
        private readonly TransportOperationsService $transport,
        private readonly EvsOperationsService $evs,
    ) {}

    /**
     * @return array<string, mixed>|null null = unknown domain, or hidden by D5
     */
    public function build(string $domain): ?array
    {
        if (! in_array($domain, self::DOMAINS, true)) {
            return null;
        }

        $snapshot = Cache::get(SnapshotBuilder::CACHE_KEY) ?? $this->snapshots->build();

        if ($domain === 'okr') {
            $tiles = $snapshot['okrs'] ?? [];
        } else {
            $section = $snapshot['domains'][$domain] ?? null;

            if ($section === null) {
                return null; // hidden demo domain (D5) — no drill either
            }

            $tiles = $section['tiles'];
        }

        return [
            'domain' => $domain,
            'title' => self::TITLES[$domain],
            'sub' => $this->subtitle($domain, $snapshot, $tiles),
            'asOf' => $snapshot['asOf'] ?? ($snapshot['generatedAtIso'] ?? now()->toIso8601String()),
            'kpis' => array_slice(array_values($tiles), 0, 6),
            'tables' => $this->safeTables($domain, $snapshot, $tiles),
            // P3 seam: the deep synthetic timeline/opportunity detail.
            'drilldownHref' => '/api/command-center/drilldown',
        ];
    }

    /** @param list<array<string, mixed>> $tiles */
    private function subtitle(string $domain, array $snapshot, array $tiles): ?string
    {
        $gaugeKey = $snapshot['domains'][$domain]['gaugeKey'] ?? null;
        $gauge = $gaugeKey !== null
            ? collect($tiles)->firstWhere('key', $gaugeKey)
            : null;

        if (is_array($gauge)) {
            return trim($gauge['label'].' '.$gauge['display'].($gauge['sub'] !== null ? ' — '.$gauge['sub'] : ''));
        }

        return $domain === 'okr' ? 'Enterprise objectives — actual vs target' : null;
    }

    /**
     * @param  list<array<string, mixed>>  $tiles
     * @return list<array<string, mixed>>
     */
    private function safeTables(string $domain, array $snapshot, array $tiles): array
    {
        try {
            return match ($domain) {
                'rtdc' => [$this->unitCapacityBoard($snapshot)],
                'ed' => $this->edTables(),
                'periop' => [$this->orRoomBoard()],
                'staffing' => [$this->unitCoverage()],
                'flow' => $this->flowTables(),
                'okr' => [$this->okrTable($tiles)],
                default => [$this->measureLedger($domain, $tiles)],
            };
        } catch (\Throwable $e) {
            Log::warning('cockpit.drill.tables_failed', ['domain' => $domain, 'error' => $e->getMessage()]);

            return [$this->measureLedger($domain, $tiles)];
        }
    }

    // -- table builders ------------------------------------------------------

    /** @return array<string, mixed> */
    private function unitCapacityBoard(array $snapshot): array
    {
        $rows = [];

        foreach ($snapshot['unitCensus'] ?? [] as $unit) {
            $rows[] = [
                'unit' => ['v' => (string) $unit['name'], 'strong' => true],
                'type' => ['v' => (string) $unit['type'], 'dim' => true],
                'staffed' => (int) $unit['staffed'],
                'occupied' => (int) $unit['occupied'],
                'available' => (int) $unit['available'],
                'blocked' => (int) $unit['blocked'],
                'occupancy' => ['bar' => [
                    'pct' => (int) $unit['occupancyPct'],
                    'status' => (string) $unit['status'],
                    'label' => $unit['occupancyPct'].'%',
                ]],
                'status' => ['chip' => (string) $unit['status']],
            ];
        }

        return [
            'caption' => 'Unit capacity board',
            'columns' => [
                ['key' => 'unit', 'header' => 'Unit', 'align' => 'left'],
                ['key' => 'type', 'header' => 'Type', 'align' => 'left'],
                ['key' => 'staffed', 'header' => 'Staffed', 'align' => 'right'],
                ['key' => 'occupied', 'header' => 'Occ', 'align' => 'right'],
                ['key' => 'available', 'header' => 'Avail', 'align' => 'right'],
                ['key' => 'blocked', 'header' => 'Blocked', 'align' => 'right'],
                ['key' => 'occupancy', 'header' => 'Occupancy', 'align' => 'left'],
                ['key' => 'status', 'header' => '', 'align' => 'right'],
            ],
            'rows' => $rows,
        ];
    }

    /** @return list<array<string, mixed>> */
    private function edTables(): array
    {
        $board = app(\App\Services\Ed\TreatmentService::class)->build();
        $rows = [];

        foreach (array_slice($board['board'] ?? [], 0, 12) as $patient) {
            $esi = (int) $patient['esiLevel'];
            $rows[] = [
                'room' => ['v' => (string) $patient['room'], 'strong' => true],
                'complaint' => (string) $patient['chiefComplaint'],
                'esi' => ['tag' => [
                    'text' => 'ESI '.$esi,
                    'status' => $esi <= 2 ? 'critical' : ($esi === 3 ? 'warning' : 'neutral'),
                ]],
                'los' => ['v' => $patient['losMinutes'].'m', 'status' => $patient['losMinutes'] > 240 ? 'warning' : 'neutral'],
                'provider' => ['v' => (string) $patient['provider'], 'dim' => true],
                'status' => ['tag' => ['text' => (string) $patient['status'], 'status' => (string) $patient['statusTone']]],
            ];
        }

        $acuityRows = array_map(fn (array $mix): array => [
            'level' => ['v' => (string) $mix['label'], 'strong' => true],
            'count' => (int) $mix['count'],
        ], $board['acuityMix'] ?? []);

        return [
            [
                'caption' => 'Active track board',
                'columns' => [
                    ['key' => 'room', 'header' => 'Bed', 'align' => 'left'],
                    ['key' => 'complaint', 'header' => 'Chief complaint', 'align' => 'left'],
                    ['key' => 'esi', 'header' => 'ESI', 'align' => 'left'],
                    ['key' => 'los', 'header' => 'LOS', 'align' => 'right'],
                    ['key' => 'provider', 'header' => 'Provider', 'align' => 'left'],
                    ['key' => 'status', 'header' => 'Status', 'align' => 'right'],
                ],
                'rows' => $rows,
            ],
            [
                'caption' => 'Acuity mix',
                'columns' => [
                    ['key' => 'level', 'header' => 'ESI level', 'align' => 'left'],
                    ['key' => 'count', 'header' => 'Patients', 'align' => 'right'],
                ],
                'rows' => $acuityRows,
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function orRoomBoard(): array
    {
        $statusTone = [
            'in_progress' => 'info',
            'delayed' => 'critical',
            'turnover' => 'warning',
            'available' => 'success',
        ];
        $rows = [];

        foreach (array_slice($this->rooms->build()['rooms'] ?? [], 0, 18) as $room) {
            $case = $room['currentCase'];
            $rows[] = [
                'room' => ['v' => 'OR '.$room['number'], 'strong' => true],
                'state' => ['tag' => [
                    'text' => str_replace('_', ' ', (string) $room['status']),
                    'status' => $statusTone[$room['status']] ?? 'neutral',
                ]],
                'case' => $case['procedure'] ?? ($room['nextCase']['procedure'] ?? '—'),
                'surgeon' => ['v' => $case['provider'] ?? '—', 'dim' => true],
                'start' => $case['startTime'] ?? ($room['nextCase']['startTime'] ?? '—'),
                'elapsed' => $case !== null ? $case['elapsed'].'m' : ($room['turnoverTime'] !== null ? $room['turnoverTime'].'m turn' : '—'),
            ];
        }

        return [
            'caption' => 'OR room board',
            'columns' => [
                ['key' => 'room', 'header' => 'Room', 'align' => 'left'],
                ['key' => 'state', 'header' => 'State', 'align' => 'left'],
                ['key' => 'case', 'header' => 'Current / next case', 'align' => 'left'],
                ['key' => 'surgeon', 'header' => 'Surgeon', 'align' => 'left'],
                ['key' => 'start', 'header' => 'Start', 'align' => 'right'],
                ['key' => 'elapsed', 'header' => 'Elapsed', 'align' => 'right'],
            ],
            'rows' => $rows,
        ];
    }

    /** @return array<string, mixed> */
    private function unitCoverage(): array
    {
        $rows = [];

        foreach ($this->staffing->overview()['units_at_risk'] ?? [] as $unit) {
            $rows[] = [
                'unit' => ['v' => (string) $unit['unit_label'], 'strong' => true],
                'gap' => ['v' => '-'.$unit['gap_headcount'], 'status' => $unit['below_minimum_safe'] ? 'critical' : 'warning', 'strong' => true],
                'role' => (string) $unit['worst_role_label'],
                'floor' => ['chip' => $unit['below_minimum_safe'] ? 'critical' : 'success'],
                'status' => ['tag' => [
                    'text' => str_replace('_', ' ', (string) $unit['status']),
                    'status' => $unit['status'] === 'critical_gap' ? 'critical' : 'warning',
                ]],
            ];
        }

        return [
            'caption' => 'Units at coverage risk',
            'columns' => [
                ['key' => 'unit', 'header' => 'Unit', 'align' => 'left'],
                ['key' => 'gap', 'header' => 'Gap', 'align' => 'right'],
                ['key' => 'role', 'header' => 'Worst role', 'align' => 'left'],
                ['key' => 'floor', 'header' => 'Safe floor', 'align' => 'right', 'note' => 'below minimum safe staffing'],
                ['key' => 'status', 'header' => 'Status', 'align' => 'right'],
            ],
            'rows' => $rows,
        ];
    }

    /** @return list<array<string, mixed>> */
    private function flowTables(): array
    {
        $measureRows = array_map(fn (array $m): array => [
            'measure' => ['v' => (string) $m['label'], 'strong' => true],
            'value' => $m['value'] !== null ? trim($m['value'].' '.$m['unit']) : '—',
            'context' => ['v' => (string) $m['caption'], 'dim' => true],
        ], $this->transport->measures());

        $priorityTone = ['stat' => 'critical', 'urgent' => 'warning'];
        $evsRows = [];

        foreach (array_slice($this->evs->overview()['queue'] ?? [], 0, 10) as $req) {
            $evsRows[] = [
                'location' => ['v' => (string) ($req['location_label'] ?? '—'), 'strong' => true],
                'type' => str_replace('_', ' ', (string) $req['request_type']),
                'priority' => ['tag' => [
                    'text' => (string) $req['priority'],
                    'status' => $priorityTone[$req['priority']] ?? 'neutral',
                ]],
                'status' => str_replace('_', ' ', (string) $req['status']),
                'sla' => ['v' => (string) ($req['sla']['label'] ?? '—'), 'status' => ($req['sla']['at_risk'] ?? false) ? 'warning' : 'neutral'],
            ];
        }

        return [
            [
                'caption' => 'Transport performance measures',
                'columns' => [
                    ['key' => 'measure', 'header' => 'Measure', 'align' => 'left'],
                    ['key' => 'value', 'header' => 'Value', 'align' => 'right'],
                    ['key' => 'context', 'header' => 'Context', 'align' => 'left'],
                ],
                'rows' => $measureRows,
            ],
            [
                'caption' => 'EVS queue',
                'columns' => [
                    ['key' => 'location', 'header' => 'Location', 'align' => 'left'],
                    ['key' => 'type', 'header' => 'Type', 'align' => 'left'],
                    ['key' => 'priority', 'header' => 'Priority', 'align' => 'left'],
                    ['key' => 'status', 'header' => 'Status', 'align' => 'left'],
                    ['key' => 'sla', 'header' => 'SLA', 'align' => 'right'],
                ],
                'rows' => $evsRows,
            ],
        ];
    }

    /** @param list<array<string, mixed>> $tiles
     * @return array<string, mixed> */
    private function okrTable(array $tiles): array
    {
        $rows = [];

        foreach ($tiles as $okr) {
            $canon = CockpitStatus::from($okr['status'])->canon();
            $rows[] = [
                'objective' => ['v' => (string) ($okr['objective'] ?? '—'), 'strong' => true],
                'keyResult' => (string) ($okr['keyResult'] ?? $okr['label']),
                'actual' => ['v' => (string) $okr['display'], 'strong' => true, 'status' => $canon],
                'target' => ['v' => $okr['target'] !== null ? (string) $okr['target'] : '—', 'dim' => true],
                'owner' => (string) ($okr['owner'] ?? '—'),
                'status' => ['chip' => $canon],
            ];
        }

        return [
            'caption' => 'Executive OKR scorecard',
            'columns' => [
                ['key' => 'objective', 'header' => 'Objective', 'align' => 'left'],
                ['key' => 'keyResult', 'header' => 'Key result', 'align' => 'left'],
                ['key' => 'actual', 'header' => 'Actual', 'align' => 'right'],
                ['key' => 'target', 'header' => 'Target', 'align' => 'right'],
                ['key' => 'owner', 'header' => 'Owner', 'align' => 'left'],
                ['key' => 'status', 'header' => '', 'align' => 'right'],
            ],
            'rows' => $rows,
        ];
    }

    /**
     * Fallback + mocked-domain ledger: the domain's own tiles as rows.
     *
     * @param  list<array<string, mixed>>  $tiles
     * @return array<string, mixed>
     */
    private function measureLedger(string $domain, array $tiles): array
    {
        $rows = [];

        foreach ($tiles as $tile) {
            $canon = CockpitStatus::from($tile['status'])->canon();
            $demo = ($tile['metadata']['provenance'] ?? null) === 'demo';
            $rows[] = [
                'measure' => ['v' => (string) $tile['label'], 'strong' => true],
                'value' => ['v' => (string) $tile['display'], 'strong' => true, 'status' => $canon],
                'target' => ['v' => $tile['target'] !== null ? (string) $tile['target'] : '—', 'dim' => true],
                'source' => ['tag' => ['text' => $demo ? 'demo' : 'live', 'status' => $demo ? 'neutral' : 'success']],
                'status' => ['chip' => $canon],
            ];
        }

        return [
            'caption' => self::TITLES[$domain].' — measures',
            'columns' => [
                ['key' => 'measure', 'header' => 'Measure', 'align' => 'left'],
                ['key' => 'value', 'header' => 'Value', 'align' => 'right'],
                ['key' => 'target', 'header' => 'Target', 'align' => 'right'],
                ['key' => 'source', 'header' => 'Source', 'align' => 'left'],
                ['key' => 'status', 'header' => '', 'align' => 'right'],
            ],
            'rows' => $rows,
        ];
    }
}
