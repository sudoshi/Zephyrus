<?php

namespace App\Services\Home;

use App\Models\Bed;
use App\Models\Encounter;
use App\Models\Home\HomeEpisode;
use App\Models\Home\HomeProgram;
use App\Models\Home\HomeReferral;
use App\Models\Home\RpmEnrollment;
use App\Models\Home\RpmKit;
use App\Models\Unit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Ramsey\Uuid\Uuid;

/**
 * Referral funnel + eligibility worklists (ACUM-PRD-HAH-001 §4.2, §7).
 *
 * The worklists are the capacity-unification thesis made operational: ED
 * candidates come off the LIVE ED census (stable-ESI boarders first — every
 * activation is a boarding hour avoided) and step-down candidates off
 * inpatient encounters at/near expected LOS. Funnel transitions are strictly
 * ordered (referred → screened → eligible → consented → activated) with
 * coded decline reasons at every stage — decline analytics are the §11
 * selection-bias guardrail, not an afterthought.
 *
 * activate() is the census-spine moment: it claims a free slot (bed) on the
 * virtual ward, opens the encounter + episode, assigns an available kit with
 * an active enrollment, and opens the inbound activation checklist.
 */
class HomeReferralService
{
    private const FUNNEL_ORDER = ['referred', 'screened', 'eligible', 'consented', 'activated'];

    public function __construct(private readonly HomeTransitionService $transitions) {}

    /** @return array<string, mixed> */
    public function build(): array
    {
        $referrals = HomeReferral::query()
            ->with('program:home_program_id,code,name')
            ->where('is_deleted', false)
            ->orderByDesc('referred_at')
            ->limit(60)
            ->get()
            ->map(fn (HomeReferral $r): array => $this->present($r));

        return [
            'funnel' => $referrals->groupBy('status')->map->values(),
            'counts' => $referrals->countBy('status'),
            'edCandidates' => $this->edCandidates(),
            'stepDownCandidates' => $this->stepDownCandidates(),
            'freeSlots' => $this->freeSlotCount(),
        ];
    }

    /**
     * Home-eligible ED candidates off the live ED census: still-in-ED or
     * boarding (admit decided, no bed), clinically stable (ESI 3–5).
     * Boarders sort first — they are the decant opportunity.
     *
     * @return list<array<string, mixed>>
     */
    public function edCandidates(): array
    {
        $known = $this->refsAlreadyInFunnel();

        return DB::table('prod.ed_visits')
            ->whereNull('departed_at')
            ->where('is_deleted', false)
            ->where(fn ($q) => $q->whereNull('disposition')->orWhere('disposition', 'admitted'))
            ->where('esi_level', '>=', 3)
            ->orderByRaw('(admit_decision_at IS NOT NULL AND bed_assigned_at IS NULL) DESC')
            ->orderBy('arrived_at')
            ->limit(20)
            ->get()
            ->reject(fn (object $v): bool => in_array($v->patient_ref, $known, true))
            ->map(fn (object $v): array => [
                'patientRef' => $v->patient_ref,
                'esiLevel' => (int) $v->esi_level,
                'arrivedAt' => $v->arrived_at,
                'isBoarding' => $v->admit_decision_at !== null && $v->bed_assigned_at === null,
                'losMinutes' => max(0, (int) round(now()->diffInMinutes($v->arrived_at, true))),
                'source' => 'ed_diversion',
            ])
            ->values()
            ->all();
    }

    /**
     * Inpatient step-down candidates: active encounters on physical wards,
     * stable acuity, at/near expected discharge — decanting them frees a
     * physical bed early.
     *
     * @return list<array<string, mixed>>
     */
    public function stepDownCandidates(): array
    {
        $known = $this->refsAlreadyInFunnel();

        return Encounter::query()
            ->with('unit:unit_id,abbreviation,type')
            ->active()
            ->whereHas('unit', fn ($q) => $q->whereIn('type', ['med_surg', 'step_down'])->where('is_deleted', false))
            ->where('acuity_tier', '>=', 3)
            ->whereNotNull('expected_discharge_date')
            ->where('expected_discharge_date', '<=', now()->addDays(2)->toDateString())
            ->orderBy('expected_discharge_date')
            ->limit(20)
            ->get()
            ->reject(fn (Encounter $e): bool => in_array($e->patient_ref, $known, true))
            ->map(fn (Encounter $e): array => [
                'patientRef' => $e->patient_ref,
                'unit' => $e->unit?->abbreviation,
                'acuityTier' => $e->acuity_tier,
                'admittedAt' => $e->admitted_at?->toIso8601String(),
                'expectedDischargeDate' => $e->expected_discharge_date?->toDateString(),
                'encounterId' => $e->encounter_id,
                'source' => 'inpatient_stepdown',
            ])
            ->values()
            ->all();
    }

    /** @param array<string, mixed> $attributes */
    public function create(array $attributes): HomeReferral
    {
        $program = HomeProgram::query()
            ->where('code', $attributes['program_code'] ?? 'ahcah_acute')
            ->where('is_active', true)
            ->firstOrFail();

        return HomeReferral::create([
            'referral_uuid' => Uuid::uuid4()->toString(),
            'home_program_id' => $program->home_program_id,
            'patient_ref' => (string) $attributes['patient_ref'],
            'encounter_id' => $attributes['encounter_id'] ?? null,
            'source' => (string) $attributes['source'],
            'status' => 'referred',
            'screening' => (array) ($attributes['screening'] ?? []),
            'payer_class' => $attributes['payer_class'] ?? null,
            'service_zone' => $attributes['service_zone'] ?? null,
            'referred_by' => $attributes['referred_by'] ?? null,
            'referred_at' => now(),
            'status_changed_at' => now(),
        ]);
    }

    /** Advance one strictly-ordered funnel step; activation claims a slot. */
    public function advance(HomeReferral $referral): HomeReferral
    {
        $index = array_search($referral->status, self::FUNNEL_ORDER, true);

        if ($index === false || $referral->status === 'activated') {
            throw ValidationException::withMessages([
                'status' => "Referral in status [{$referral->status}] cannot advance.",
            ]);
        }

        $next = self::FUNNEL_ORDER[$index + 1];

        if ($next === 'activated') {
            return $this->activate($referral);
        }

        $referral->update(['status' => $next, 'status_changed_at' => now()]);

        return $referral->fresh();
    }

    public function decline(HomeReferral $referral, string $reason, ?string $note = null): HomeReferral
    {
        if (in_array($referral->status, ['activated', 'declined', 'cancelled'], true)) {
            throw ValidationException::withMessages([
                'status' => "Referral in status [{$referral->status}] cannot be declined.",
            ]);
        }

        $referral->update([
            'status' => 'declined',
            'decline_reason' => $reason,
            'decline_note' => $note,
            'declined_at' => now(),
            'status_changed_at' => now(),
        ]);

        return $referral->fresh();
    }

    /**
     * Consented → activated: claim a free virtual-ward slot, open the
     * census-spine encounter + episode, assign a kit, open the inbound
     * activation checklist. Fails loudly when the ward is full — a referral
     * must never activate into thin air.
     */
    public function activate(HomeReferral $referral): HomeReferral
    {
        if ($referral->status !== 'consented') {
            throw ValidationException::withMessages([
                'status' => 'Only a consented referral can be activated.',
            ]);
        }

        return DB::transaction(function () use ($referral): HomeReferral {
            $ward = Unit::query()
                ->where('type', 'virtual_home')
                ->where('is_deleted', false)
                ->orderBy('unit_id')
                ->firstOrFail();

            $slot = Bed::query()
                ->where('unit_id', $ward->unit_id)
                ->where('status', 'available')
                ->where('is_deleted', false)
                ->orderBy('bed_id')
                ->lockForUpdate()
                ->first();

            if ($slot === null) {
                throw ValidationException::withMessages([
                    'slot' => 'No free virtual-ward slot — activation would exceed program capacity.',
                ]);
            }

            $screening = (array) $referral->screening;
            $conditionCode = (string) ($screening['condition_code'] ?? 'unspecified');

            $encounter = Encounter::create([
                'patient_ref' => $referral->patient_ref,
                'unit_id' => $ward->unit_id,
                'bed_id' => $slot->bed_id,
                'admitted_at' => now(),
                'expected_discharge_date' => now()->addDays(6)->toDateString(),
                'acuity_tier' => (int) ($screening['acuity_tier'] ?? 3),
                'status' => 'active',
                'created_by' => 'home-hospital',
            ]);
            $slot->update(['status' => 'occupied', 'modified_by' => 'home-hospital']);

            $episode = HomeEpisode::create([
                'episode_uuid' => Uuid::uuid4()->toString(),
                'home_program_id' => $referral->home_program_id,
                'home_referral_id' => $referral->home_referral_id,
                'encounter_id' => $encounter->encounter_id,
                'patient_ref' => $referral->patient_ref,
                'condition_code' => $conditionCode,
                'condition_label' => $screening['condition_label'] ?? null,
                'admission_source' => $referral->source,
                'acuity_tier' => (int) ($screening['acuity_tier'] ?? 3),
                'status' => 'active',
                'service_zone' => $referral->service_zone,
                'target_los_days' => 6.0,
                'expected_discharge_date' => now()->addDays(6)->toDateString(),
                'started_at' => now(),
            ]);

            $kit = RpmKit::query()
                ->where('status', 'available')
                ->where('is_deleted', false)
                ->orderBy('rpm_kit_id')
                ->lockForUpdate()
                ->first();

            if ($kit !== null) {
                RpmEnrollment::create([
                    'enrollment_uuid' => Uuid::uuid4()->toString(),
                    'home_episode_id' => $episode->home_episode_id,
                    'rpm_kit_id' => $kit->rpm_kit_id,
                    'patient_ref' => $referral->patient_ref,
                    'status' => 'active',
                    'monitoring_plan' => ['cadence_minutes' => ['8867-4' => 60, '59408-5' => 60, '8480-6' => 240, '8462-4' => 240]],
                    'started_at' => now(),
                ]);
                $kit->update(['status' => 'assigned']);
            }

            $this->transitions->ensureInbound($episode);

            $referral->update([
                'status' => 'activated',
                'activated_at' => now(),
                'status_changed_at' => now(),
            ]);

            return $referral->fresh();
        });
    }

    /** @return array<string, mixed> */
    private function present(HomeReferral $r): array
    {
        return [
            'referralUuid' => $r->referral_uuid,
            'patientRef' => $r->patient_ref,
            'program' => $r->program?->code,
            'source' => $r->source,
            'status' => $r->status,
            'payerClass' => $r->payer_class,
            'serviceZone' => $r->service_zone,
            'declineReason' => $r->decline_reason,
            'referredAt' => $r->referred_at?->toIso8601String(),
            'statusChangedAt' => $r->status_changed_at?->toIso8601String(),
            'screening' => collect((array) $r->screening)->except('street_address')->all(),
        ];
    }

    /** @return list<string> */
    private function refsAlreadyInFunnel(): array
    {
        return HomeReferral::query()
            ->where('is_deleted', false)
            ->whereNotIn('status', ['declined', 'cancelled'])
            ->pluck('patient_ref')
            ->merge(HomeEpisode::query()->active()->pluck('patient_ref'))
            ->unique()
            ->values()
            ->all();
    }

    private function freeSlotCount(): int
    {
        return Bed::query()
            ->join('prod.units', 'prod.units.unit_id', '=', 'prod.beds.unit_id')
            ->where('prod.units.type', 'virtual_home')
            ->where('prod.beds.status', 'available')
            ->where('prod.beds.is_deleted', false)
            ->count();
    }
}
