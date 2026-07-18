<?php

namespace App\Services\Home;

use App\Models\Home\HomeEpisode;
use App\Models\Home\HomeVisit;
use App\Models\Home\RpmKit;

/**
 * Field Operations & Logistics (/home/logistics) — ACUM-PRD-HAH-001 §4.2.
 *
 * ⚠ THE ONE ADDRESS CONTEXT. This service is the only code path allowed to
 * read episode metadata.logistics_address; every other Home payload carries
 * pseudonymous refs and service zones only (build brief §8.4). A test greps
 * the other surfaces to keep this contract honest.
 */
class HomeLogisticsService
{
    /** @return array<string, mixed> */
    public function build(): array
    {
        $episodes = HomeEpisode::query()
            ->with('program:home_program_id,code')
            ->active()
            ->whereHas('program', fn ($q) => $q->where('program_type', 'ahcah_acute'))
            ->get()
            ->keyBy('home_episode_id');

        $today = HomeVisit::query()
            ->with('episode:home_episode_id,service_zone,metadata')
            ->whereIn('home_episode_id', $episodes->keys())
            ->where('is_deleted', false)
            ->whereBetween('scheduled_start', [now()->startOfDay(), now()->endOfDay()])
            ->orderBy('scheduled_start')
            ->get();

        $rail = $episodes->map(function (HomeEpisode $episode) use ($today): array {
            $visits = $today->where('home_episode_id', $episode->home_episode_id);
            $waiver = $visits->where('is_waiver_required', true);

            return [
                'patientRef' => $episode->patient_ref,
                'serviceZone' => $episode->service_zone,
                // Restricted context: the street address surfaces HERE ONLY.
                'address' => data_get($episode->metadata, 'logistics_address'),
                'waiverRequired' => (int) config('home_hospital.waiver.required_visits_per_day', 2),
                'waiverCompleted' => $waiver->where('status', 'completed')->count(),
                'waiverScheduled' => $waiver->where('status', 'scheduled')->count(),
                'compliant' => $waiver->where('status', 'completed')->count()
                    + $waiver->where('status', 'scheduled')->count()
                    >= (int) config('home_hospital.waiver.required_visits_per_day', 2),
                'visits' => $visits->map(fn (HomeVisit $v): array => [
                    'visitUuid' => $v->visit_uuid,
                    'type' => $v->visit_type,
                    'status' => $v->status,
                    'scheduledStart' => $v->scheduled_start?->toIso8601String(),
                    'assignedTo' => $v->assigned_to,
                    'isWaiverRequired' => (bool) $v->is_waiver_required,
                    'onTime' => $v->on_time,
                ])->values()->all(),
            ];
        })->values();

        $assignments = $today
            ->whereIn('status', ['scheduled', 'en_route', 'in_progress'])
            ->groupBy(fn (HomeVisit $v): string => $v->assigned_to ?? 'Unassigned')
            ->map(fn ($visits, string $assignee): array => [
                'assignee' => $assignee,
                'stops' => $visits->map(fn (HomeVisit $v): array => [
                    'patientRef' => $v->patient_ref,
                    'type' => $v->visit_type,
                    'scheduledStart' => $v->scheduled_start?->toIso8601String(),
                    'serviceZone' => optional($v->episode)->service_zone,
                    'address' => data_get(optional($v->episode)->metadata, 'logistics_address'),
                ])->values()->all(),
            ])
            ->values();

        return [
            'complianceRail' => $rail->all(),
            'assignments' => $assignments->all(),
            'kits' => [
                'byStatus' => RpmKit::query()->where('is_deleted', false)
                    ->selectRaw('status, count(*) AS n')->groupBy('status')->pluck('n', 'status'),
                'lowBattery' => RpmKit::query()->where('is_deleted', false)
                    ->where('battery_pct', '<', 30)->count(),
            ],
            'deliveries' => HomeVisit::query()
                ->whereIn('visit_type', ['delivery', 'lab_draw'])
                ->where('is_deleted', false)
                ->where('scheduled_start', '>=', now()->subDay())
                ->orderBy('scheduled_start')
                ->limit(20)
                ->get()
                ->map(fn (HomeVisit $v): array => [
                    'visitUuid' => $v->visit_uuid,
                    'patientRef' => $v->patient_ref,
                    'type' => $v->visit_type,
                    'status' => $v->status,
                    'scheduledStart' => $v->scheduled_start?->toIso8601String(),
                ])->values()->all(),
        ];
    }
}
