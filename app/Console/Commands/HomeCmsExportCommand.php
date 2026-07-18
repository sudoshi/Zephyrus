<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * CMS AHCAH waiver + 2028-study export (ACUM-PRD-HAH-001 §8).
 *
 * The Consolidated Appropriations Act, 2026 directs HHS to report by
 * 2028-09-30 on home vs brick-and-mortar inpatient care: readmissions,
 * mortality, escalations/transfers, LOS, staffing, patient experience,
 * conditions, cost, and EQUITY (explicitly addressing selection bias).
 * Because the module captures those variables as first-class operational
 * data, this export is a byproduct — every number is computed from the
 * prod.home_* tables, pseudonymous throughout, with the national benchmarks
 * (Levine 2024 / CMS waiver floor) attached for comparison.
 */
class HomeCmsExportCommand extends Command
{
    protected $signature = 'home:cms-export
        {--from= : Window start (default: first day of last month)}
        {--to= : Window end (default: now)}
        {--output= : Write JSON to this path instead of stdout}';

    protected $description = 'Export CMS AHCAH waiver + 2028-study measures from the Home Hospital operational tables';

    public function handle(): int
    {
        if (! (bool) config('home_hospital.enabled')) {
            $this->error('Home Hospital is not enabled (HOME_HOSPITAL_ENABLED).');

            return self::FAILURE;
        }

        $from = $this->option('from') ? now()->parse((string) $this->option('from')) : now()->subMonth()->startOfMonth();
        $to = $this->option('to') ? now()->parse((string) $this->option('to')) : now();

        $report = [
            'report' => 'ACUM-PRD-HAH-001 CMS AHCAH waiver / 2028-study export',
            'window' => ['from' => $from->toIso8601String(), 'to' => $to->toIso8601String()],
            'generated_at' => now()->toIso8601String(),
            'episodes' => $this->episodes($from, $to),
            'escalations' => $this->escalations($from, $to),
            'visit_compliance' => $this->visitCompliance($from, $to),
            'monitoring' => $this->monitoring($from, $to),
            'equity_selection_bias' => $this->equity($from, $to),
            'benchmarks' => [
                'escalation_rate_pct' => ['value' => 6.2, 'source' => 'Levine et al. 2024'],
                'in_episode_mortality_pct' => ['value' => 0.5, 'source' => 'Levine et al. 2024'],
                'readmission_30d_pct' => ['value' => 15.6, 'source' => 'Levine et al. 2024'],
                'mean_los_days' => ['value' => 6.3, 'source' => 'Levine et al. 2024'],
                'emergency_response_minutes' => ['value' => 30, 'source' => 'CMS waiver / MedPAC 2024'],
                'visits_per_day_floor' => ['value' => 2, 'source' => 'CMS waiver / MedPAC 2024'],
            ],
        ];

        $json = json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);

        if ($this->option('output')) {
            file_put_contents((string) $this->option('output'), $json.PHP_EOL);
            $this->info('Wrote '.$this->option('output'));
        } else {
            $this->line($json);
        }

        return self::SUCCESS;
    }

    /** @return array<string, mixed> */
    private function episodes($from, $to): array
    {
        $row = DB::selectOne("
            SELECT count(*) AS started,
                   count(*) FILTER (WHERE e.status = 'completed') AS completed,
                   ROUND(AVG(EXTRACT(EPOCH FROM (e.ended_at - e.started_at)) / 86400.0)
                       FILTER (WHERE e.ended_at IS NOT NULL)::numeric, 2) AS mean_los_days,
                   count(*) FILTER (WHERE e.disposition = 'deceased') AS deaths,
                   count(*) FILTER (WHERE e.disposition IN ('ed_return', 'readmitted')) AS returns_to_hospital
            FROM prod.home_episodes e
            JOIN prod.home_programs p ON p.home_program_id = e.home_program_id
            WHERE e.is_deleted = false AND p.program_type = 'ahcah_acute'
              AND e.started_at BETWEEN ? AND ?
        ", [$from, $to]);

        $started = (int) ($row->started ?? 0);

        $conditions = DB::table('prod.home_episodes as e')
            ->join('prod.home_programs as p', 'p.home_program_id', '=', 'e.home_program_id')
            ->where('e.is_deleted', false)
            ->where('p.program_type', 'ahcah_acute')
            ->whereBetween('e.started_at', [$from, $to])
            ->selectRaw('e.condition_code, count(*) AS n')
            ->groupBy('e.condition_code')
            ->orderByDesc('n')
            ->pluck('n', 'condition_code');

        return [
            'started' => $started,
            'completed' => (int) $row->completed,
            'mean_los_days' => $row->mean_los_days !== null ? (float) $row->mean_los_days : null,
            'in_episode_mortality_pct' => $started > 0 ? round(100.0 * (int) $row->deaths / $started, 2) : null,
            'return_to_hospital_pct' => $started > 0 ? round(100.0 * (int) $row->returns_to_hospital / $started, 2) : null,
            'by_condition' => $conditions,
            'note' => 'return_to_hospital counts ed_return + readmitted dispositions; index-linked 30-day readmission requires cross-encounter linkage (Phase Later).',
        ];
    }

    /** @return array<string, mixed> */
    private function escalations($from, $to): array
    {
        $row = DB::selectOne('
            SELECT count(*) AS total,
                   percentile_cont(0.9) WITHIN GROUP (ORDER BY response_minutes) AS response_p90,
                   count(*) FILTER (WHERE response_minutes <= 30) AS within_floor,
                   count(*) FILTER (WHERE response_minutes IS NOT NULL) AS with_response
            FROM prod.home_escalations
            WHERE is_deleted = false AND initiated_at BETWEEN ? AND ?
        ', [$from, $to]);

        $outcomes = DB::table('prod.home_escalations')
            ->where('is_deleted', false)
            ->whereBetween('initiated_at', [$from, $to])
            ->whereNotNull('outcome')
            ->selectRaw('outcome, count(*) AS n')
            ->groupBy('outcome')
            ->pluck('n', 'outcome');

        $withResponse = (int) ($row->with_response ?? 0);

        return [
            'total' => (int) ($row->total ?? 0),
            'response_p90_minutes' => $row->response_p90 !== null ? round((float) $row->response_p90, 1) : null,
            'within_30min_floor_pct' => $withResponse > 0 ? round(100.0 * (int) $row->within_floor / $withResponse, 1) : null,
            'outcomes' => $outcomes,
        ];
    }

    /** @return array<string, mixed> */
    private function visitCompliance($from, $to): array
    {
        $row = DB::selectOne("
            SELECT count(*) FILTER (WHERE is_waiver_required AND status = 'completed') AS waiver_completed,
                   count(*) FILTER (WHERE is_waiver_required AND status IN ('completed', 'missed')) AS waiver_due,
                   count(*) FILTER (WHERE is_waiver_required AND status = 'completed' AND on_time) AS waiver_on_time
            FROM prod.home_visits
            WHERE is_deleted = false AND scheduled_start BETWEEN ? AND ?
        ", [$from, $to]);

        $due = (int) ($row->waiver_due ?? 0);
        $completed = (int) ($row->waiver_completed ?? 0);

        return [
            'waiver_visits_due' => $due,
            'waiver_visits_completed' => $completed,
            'compliance_pct' => $due > 0 ? round(100.0 * $completed / $due, 1) : null,
            'on_time_pct' => $completed > 0 ? round(100.0 * (int) $row->waiver_on_time / $completed, 1) : null,
        ];
    }

    /** @return array<string, mixed> */
    private function monitoring($from, $to): array
    {
        $row = DB::selectOne("
            SELECT count(*) AS observations,
                   count(*) FILTER (WHERE quality_flag <> 'ok') AS flagged,
                   count(*) FILTER (WHERE is_breach) AS breaches
            FROM prod.rpm_observations
            WHERE is_deleted = false AND observed_at BETWEEN ? AND ?
        ", [$from, $to]);

        $alerts = DB::selectOne('
            SELECT count(*) AS total,
                   ROUND(AVG(EXTRACT(EPOCH FROM (acknowledged_at - opened_at)) / 60.0)
                       FILTER (WHERE acknowledged_at IS NOT NULL)::numeric, 1) AS mean_ack_minutes
            FROM prod.rpm_alerts
            WHERE is_deleted = false AND opened_at BETWEEN ? AND ?
        ', [$from, $to]);

        return [
            'observations' => (int) ($row->observations ?? 0),
            'quality_flagged' => (int) ($row->flagged ?? 0),
            'breaches' => (int) ($row->breaches ?? 0),
            'patient_alerts' => (int) ($alerts->total ?? 0),
            'mean_acknowledge_minutes' => $alerts->mean_ack_minutes !== null ? (float) $alerts->mean_ack_minutes : null,
        ];
    }

    /**
     * The equity block the 2028 study explicitly demands: who is screened out
     * and why, by payer and zone — selection bias surfaced before it becomes
     * a federal finding.
     *
     * @return array<string, mixed>
     */
    private function equity($from, $to): array
    {
        $base = DB::table('prod.home_referrals')
            ->where('is_deleted', false)
            ->whereBetween('referred_at', [$from, $to]);

        return [
            'referrals' => (clone $base)->count(),
            'decline_reasons' => (clone $base)->where('status', 'declined')
                ->selectRaw('decline_reason, count(*) AS n')
                ->groupBy('decline_reason')->pluck('n', 'decline_reason'),
            'by_payer_class' => (clone $base)
                ->selectRaw("COALESCE(payer_class, 'unknown') AS payer, count(*) AS n")
                ->groupBy('payer')->pluck('n', 'payer'),
            'activation_rate_by_payer' => (clone $base)
                ->selectRaw("COALESCE(payer_class, 'unknown') AS payer,
                    ROUND(100.0 * count(*) FILTER (WHERE status = 'activated') / count(*), 1) AS pct")
                ->groupBy('payer')->pluck('pct', 'payer'),
            'by_service_zone' => (clone $base)
                ->selectRaw("COALESCE(service_zone, 'unknown') AS zone, count(*) AS n")
                ->groupBy('zone')->pluck('n', 'zone'),
        ];
    }
}
