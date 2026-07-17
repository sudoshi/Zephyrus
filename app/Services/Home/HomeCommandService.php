<?php

namespace App\Services\Home;

use App\Models\Home\HomeEpisode;
use App\Models\Home\RpmAlert;
use App\Models\Home\RpmObservation;
use App\Models\Unit;
use Illuminate\Support\Collection;

/**
 * Virtual Ward Command (/home/command) — the Phase 1 flagship surface
 * (ACUM-PRD-HAH-001 §4.2). One payload per episode tile: pseudonymous
 * identity, condition + program, day-of-stay vs expected LOS, vitals
 * sparklines, HEWS chip, open alerts, next required visit, device status.
 *
 * Earned urgency: `breach` is true ONLY for an unacknowledged critical vital
 * — the single condition that turns a tile coral in Phase 1. Sort order is
 * the escalation-risk order (breach first, then HEWS, then acuity) so the
 * sickest tile is always top-left.
 */
class HomeCommandService
{
    private const SPARKLINE_LOINCS = ['59408-5', '8867-4']; // SpO2, HR

    private const SPARKLINE_POINTS = 12;

    public function __construct(private readonly HewsService $hews) {}

    /** @return array<string, mixed> */
    public function build(): array
    {
        $ward = Unit::where('type', 'virtual_home')->where('is_deleted', false)->orderBy('unit_id')->first();

        $episodes = HomeEpisode::query()
            ->with([
                'program:home_program_id,code,name',
                'encounter.bed:bed_id,label',
                'enrollments' => fn ($q) => $q->where('status', 'active')->with('kit:rpm_kit_id,kit_code,connectivity,battery_pct,last_seen_at'),
            ])
            ->active()
            ->get();

        $enrollmentIds = $episodes->flatMap(fn (HomeEpisode $e) => $e->enrollments->pluck('rpm_enrollment_id'))->all();

        $seriesByEnrollment = $this->vitalSeries($enrollmentIds);

        $alertsByEpisode = RpmAlert::query()
            ->whereIn('home_episode_id', $episodes->pluck('home_episode_id'))
            ->whereIn('status', ['open', 'acknowledged'])
            ->where('is_deleted', false)
            ->orderByRaw("CASE severity WHEN 'critical' THEN 0 WHEN 'warning' THEN 1 ELSE 2 END")
            ->orderByDesc('opened_at')
            ->get()
            ->groupBy('home_episode_id');

        $tiles = $episodes->map(fn (HomeEpisode $episode): array => $this->tile($episode, $seriesByEnrollment, $alertsByEpisode))
            ->sortBy([
                ['breach', 'desc'],
                ['hewsScore', 'desc'],
                ['acuityTier', 'asc'],
            ])
            ->values();

        return [
            'ward' => $ward === null ? null : ['name' => $ward->name, 'abbreviation' => $ward->abbreviation],
            'episodes' => $tiles->map(fn (array $t): array => collect($t)->except('hewsScore')->all())->all(),
            'summary' => [
                'active' => $tiles->count(),
                'breaches' => $tiles->where('breach', true)->count(),
                'highRisk' => $tiles->where('hews.band', 'high')->count(),
                'openAlerts' => $alertsByEpisode->flatten()->count(),
            ],
        ];
    }

    /**
     * @param  array<int, array<string, array<string, mixed>>>  $seriesByEnrollment
     * @param  Collection<int|string, Collection<int, RpmAlert>>  $alertsByEpisode
     * @return array<string, mixed>
     */
    private function tile(HomeEpisode $episode, array $seriesByEnrollment, Collection $alertsByEpisode): array
    {
        $enrollment = $episode->enrollments->first();
        $kit = $enrollment?->kit;
        $hews = $this->hews->computeForEpisode($episode);
        $alerts = $alertsByEpisode->get($episode->home_episode_id, collect());

        $breach = $alerts->contains(
            fn (RpmAlert $a): bool => $a->severity === 'critical' && $a->status === 'open'
        );

        $nextVisit = $episode->visits()
            ->where('status', 'scheduled')
            ->where('is_deleted', false)
            ->orderBy('scheduled_start')
            ->first();

        $online = $kit?->last_seen_at !== null && $kit->last_seen_at->gt(now()->subMinutes(60));

        return [
            'episodeUuid' => $episode->episode_uuid,
            'patientRef' => $episode->patient_ref,
            'slotLabel' => $episode->encounter?->bed?->label,
            'program' => $episode->program?->code,
            'conditionLabel' => $episode->condition_label ?? $episode->condition_code,
            'acuityTier' => $episode->acuity_tier,
            'serviceZone' => $episode->service_zone,
            'dayOfStay' => $episode->started_at !== null ? max(1, (int) $episode->started_at->diffInDays(now()) + 1) : null,
            'targetLosDays' => $episode->target_los_days,
            'expectedDischargeDate' => $episode->expected_discharge_date?->toDateString(),
            'hews' => $hews === null ? null : [
                'score' => $hews['score'],
                'band' => $hews['band'],
                'components' => $hews['components'],
            ],
            'hewsScore' => $hews['score'] ?? -1,
            'vitals' => $enrollment === null ? [] : array_values($seriesByEnrollment[$enrollment->rpm_enrollment_id] ?? []),
            'alerts' => [
                'open' => $alerts->where('status', 'open')->count(),
                'acknowledged' => $alerts->where('status', 'acknowledged')->count(),
                'critical' => $alerts->where('severity', 'critical')->count(),
                'items' => $alerts->take(3)->map(fn (RpmAlert $a): array => [
                    'alertUuid' => $a->alert_uuid,
                    'ruleKey' => $a->rule_key,
                    'severity' => $a->severity,
                    'status' => $a->status,
                    'display' => $a->metadata['display'] ?? null,
                    'value' => $a->metadata['last_value'] ?? $a->metadata['value'] ?? null,
                    'unit' => $a->metadata['unit'] ?? null,
                    'openedAt' => $a->opened_at?->toIso8601String(),
                ])->values()->all(),
            ],
            'nextVisit' => $nextVisit === null ? null : [
                'type' => $nextVisit->visit_type,
                'scheduledStart' => $nextVisit->scheduled_start?->toIso8601String(),
                'isWaiverRequired' => (bool) $nextVisit->is_waiver_required,
            ],
            'device' => $kit === null ? null : [
                'kitCode' => $kit->kit_code,
                'connectivity' => $kit->connectivity,
                'batteryPct' => $kit->battery_pct,
                'lastSeenAt' => $kit->last_seen_at?->toIso8601String(),
                'online' => $online,
            ],
            'breach' => $breach,
            'provenance' => data_get($episode->metadata, 'provenance'),
        ];
    }

    /**
     * Batched sparkline series: last N observations per (enrollment, vital)
     * for the two headline vitals, chronological.
     *
     * @param  list<int>  $enrollmentIds
     * @return array<int, array<string, array<string, mixed>>>
     */
    private function vitalSeries(array $enrollmentIds): array
    {
        if ($enrollmentIds === []) {
            return [];
        }

        $rows = RpmObservation::query()
            ->whereIn('rpm_enrollment_id', $enrollmentIds)
            ->whereIn('loinc_code', self::SPARKLINE_LOINCS)
            ->where('is_deleted', false)
            ->where('observed_at', '>=', now()->subHours(24))
            ->orderByDesc('observed_at')
            ->limit(count($enrollmentIds) * count(self::SPARKLINE_LOINCS) * self::SPARKLINE_POINTS)
            ->get(['rpm_enrollment_id', 'loinc_code', 'display', 'unit', 'value', 'observed_at']);

        $series = [];
        foreach ($rows->groupBy('rpm_enrollment_id') as $enrollmentId => $group) {
            foreach ($group->groupBy('loinc_code') as $loinc => $observations) {
                $window = $observations->take(self::SPARKLINE_POINTS)->reverse()->values();
                $latest = $observations->first();
                $series[$enrollmentId][$loinc] = [
                    'loinc' => $loinc,
                    'display' => $latest->display ?? $loinc,
                    'unit' => $latest->unit,
                    'latest' => (float) $latest->value,
                    'series' => $window->pluck('value')->map(fn ($v): float => (float) $v)->all(),
                ];
            }
        }

        return $series;
    }
}
