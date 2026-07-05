<?php

namespace Database\Seeders;

use App\Models\PatientFlow\FlowEncounter;
use App\Models\PatientFlow\FlowEvent;
use App\Models\PatientFlow\PatientIdentity;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ClinicalPathwaySeeder
 * ---------------------
 * Synthesises the clinical *pathway-step* events that the operational event
 * store does not capture, so that object-centric conformance analysis
 * (Part X / X.7 safety guardrails) has realistic data to mine.
 *
 * The audit (2026-07-04) found Zephyrus is well-instrumented for logistics
 * (beds, transport, EVS, OR phases, ED milestones) but carries no per-encounter
 * clinical trajectory: sepsis had only aggregate SEP-3 counts, stroke had a
 * bare CT skeleton, and the surgical-safety tables were empty. This seeder
 * fills those gaps with clinically-correct, timing-realistic events that
 * include a deliberate mix of CONFORMANT and DEVIANT trajectories — the
 * deviations are the point, since a conformance model with nothing to flag
 * proves nothing.
 *
 * Writes:
 *   - flow_core.flow_events (+ patient_identities / encounters) : sepsis bundle,
 *     acute-ischemic-stroke pathway, with real LOINC / RxNorm / ICD-10 codes.
 *   - prod.case_timings          : OR phase timeline (periop map / OPerA).
 *   - prod.care_journey_milestones : WHO surgical-safety checklist + pre-op journey.
 *   - prod.case_safety_notes     : deviation provenance (Safety_Alert).
 *   - prod.or_cases.safety_status / journey_progress : rolled up from milestones.
 *
 * Idempotent: flow_core rows are tagged metadata->seeder = 'clinical_pathways'
 * and deleted-by-tag; OR-side rows are deleted for the seeded case cohort
 * (those tables are otherwise unpopulated) before reinsert. Additive and
 * reversible — no prod fact data is mutated destructively and no protected
 * auth surface is touched.
 */
class ClinicalPathwaySeeder extends Seeder
{
    private const SEED = 20260704;

    private const TAG = 'clinical_pathways';

    /** Trailing analytic window — matches the Arena's 30–90d OCEL window. */
    private const WINDOW_DAYS = 45;

    private const SEPSIS_COHORT = 60;

    private const STROKE_COHORT = 26;

    /** SEP-3 3-hour antibiotic target (minutes from sepsis recognition). */
    private const SEPSIS_ABX_TARGET_MIN = 180;

    /** Stroke door-to-CT and door-to-needle targets (minutes). */
    private const STROKE_DOOR_TO_CT_MIN = 25;

    private const STROKE_DOOR_TO_NEEDLE_MIN = 60;

    // --- Clinical vocabulary (correct standard codes) -----------------------

    /** LOINC. */
    private const LOINC_LACTATE = '2524-7';         // Lactate [Moles/volume] in Ser/Plas

    private const LOINC_BLOOD_CULTURE = '600-7';    // Bacteria identified in Blood by Culture

    private const LOINC_WBC = '6690-2';             // Leukocytes [#/volume] in Blood

    private const LOINC_PROCALCITONIN = '33959-8';  // Procalcitonin [Mass/volume] in Serum

    private const LOINC_TEMP = '8310-5';

    private const LOINC_HR = '8867-4';

    private const LOINC_RR = '9279-1';

    private const LOINC_SBP = '8480-6';

    private const LOINC_MAP = '8478-0';

    private const LOINC_NIHSS = '70182-1';          // NIH stroke scale total

    private const CT_HEAD = 'CTHEAD';               // matches existing skeleton code

    private const RAD_IMPRESSION = 'RADIMP';

    /** RxNorm ingredients (broad-spectrum sepsis abx, pressors, thrombolytics, fluids). */
    private const RX_PIPTAZO = '1659149';           // piperacillin/tazobactam inj

    private const RX_VANCOMYCIN = '11124';

    private const RX_CEFTRIAXONE = '2193';

    private const RX_CEFEPIME = '20481';

    private const RX_MEROPENEM = '29561';

    private const RX_NOREPINEPHRINE = '7512';

    private const RX_ALTEPLASE = '8410';

    private const RX_TENECTEPLASE = '108046';

    private const RX_NACL = '9863';                 // 0.9% sodium chloride (fluid bolus)

    /** ICD-10-CM. */
    private const DX_SEPSIS = 'A41.9';

    private const DX_SEVERE_SEPSIS = 'R65.20';

    private const DX_SEPTIC_SHOCK = 'R65.21';

    private const DX_ISCHEMIC_STROKE = 'I63.9';

    private const DX_HEMORRHAGIC_STROKE = 'I61.9';

    private const SEPSIS_SERVICE_LINES = ['critical_care', 'medicine', 'adult_med_surg', 'oncology', 'trauma_surgery'];

    private int $rngState = self::SEED;

    private CarbonImmutable $windowStart;

    private CarbonImmutable $windowEnd;

    /** @var array<string,int> */
    private array $counts = ['flow_events' => 0, 'sepsis' => 0, 'stroke' => 0, 'timings' => 0, 'milestones' => 0, 'safety_notes' => 0];

    public function run(): void
    {
        if (! Schema::hasTable('flow_core.flow_events')) {
            $this->command?->warn('ClinicalPathwaySeeder: flow_core.flow_events missing — run migrations first. Skipping.');

            return;
        }

        $this->windowEnd = CarbonImmutable::now();
        $this->windowStart = $this->windowEnd->subDays(self::WINDOW_DAYS);
        $this->rngState = self::SEED;

        $this->cleanup();

        DB::transaction(function (): void {
            $this->seedSepsis();
            $this->seedStroke();
        });

        $this->seedSurgicalSafety();

        $this->command?->info(sprintf(
            'ClinicalPathwaySeeder: %d flow_events (%d sepsis / %d stroke encounters), %d case_timings, %d milestones, %d safety notes.',
            $this->counts['flow_events'],
            $this->counts['sepsis'],
            $this->counts['stroke'],
            $this->counts['timings'],
            $this->counts['milestones'],
            $this->counts['safety_notes'],
        ));
    }

    // -- Idempotency ---------------------------------------------------------

    private function cleanup(): void
    {
        // FK order: events → encounters → identities.
        FlowEvent::query()->where('metadata->seeder', self::TAG)->delete();
        FlowEncounter::query()->where('metadata->seeder', self::TAG)->delete();
        PatientIdentity::query()->where('metadata->seeder', self::TAG)->delete();

        $caseIds = $this->surgicalCohortCaseIds();
        if ($caseIds->isNotEmpty()) {
            DB::table('prod.case_timings')->whereIn('case_id', $caseIds)->delete();
            DB::table('prod.care_journey_milestones')->whereIn('case_id', $caseIds)->delete();
            DB::table('prod.case_safety_notes')->whereIn('case_id', $caseIds)->delete();
        }
    }

    // -- Sepsis bundle -------------------------------------------------------

    private function seedSepsis(): void
    {
        for ($i = 1; $i <= self::SEPSIS_COHORT; $i++) {
            $serviceLine = $this->pick(self::SEPSIS_SERVICE_LINES);
            $ctx = $this->openEncounter('SEP', $i, 'sepsis', $serviceLine, 'I', 'inpatient');

            $t0 = $this->randomOnset();

            // Severity mix: 55% sepsis, 30% severe sepsis, 15% septic shock.
            $roll = $this->nextInt(1, 100);
            $shock = $roll > 85;
            $severe = $roll > 55;
            $dx = [self::DX_SEPSIS];
            if ($shock) {
                $dx[] = self::DX_SEPTIC_SHOCK;
            } elseif ($severe) {
                $dx[] = self::DX_SEVERE_SEPSIS;
            }

            // Deviations (the point of the exercise).
            $abxLate = $this->chance(22);
            $cultureAfterAbx = $this->chance(12);
            $noRepeatLactate = $this->chance(18);
            $hypotensive = $shock || $severe && $this->chance(50);
            $noFluidsWhenIndicated = $hypotensive && $this->chance(15);
            $deviations = [];

            $lactate = $this->nextFloat($severe ? 3.0 : 1.6, $shock ? 8.0 : 4.5);
            $seq = 0;

            $this->writeEvent($ctx, 'clinical_context', $t0, [], [], [], $dx, 'sepsis_recognition', [
                'qsofa' => $this->nextInt($shock ? 2 : 1, 3),
                'severity' => $shock ? 'septic_shock' : ($severe ? 'severe_sepsis' : 'sepsis'),
            ], $seq++);

            $this->writeEvent($ctx, 'observation', $t0->addMinutes(2), [], [
                self::LOINC_TEMP, self::LOINC_HR, self::LOINC_RR, self::LOINC_SBP, self::LOINC_MAP, self::LOINC_WBC,
            ], [], $dx, 'vitals_sirs', [
                'temp_c' => round($this->nextFloat(38.3, 40.1), 1),
                'hr' => $this->nextInt(96, 138),
                'rr' => $this->nextInt(22, 34),
                'sbp' => $hypotensive ? $this->nextInt(72, 90) : $this->nextInt(96, 118),
                'map' => $hypotensive ? $this->nextInt(50, 64) : $this->nextInt(66, 80),
                'wbc' => round($this->nextFloat(13.5, 24.0), 1),
            ], $seq++);

            $lactateOrderAt = $t0->addMinutes($this->nextInt(8, 20));
            $this->writeEvent($ctx, 'order', $lactateOrderAt, [self::LOINC_LACTATE], [], [], $dx, 'lactate_order', [], $seq++);
            $this->writeEvent($ctx, 'observation', $lactateOrderAt->addMinutes($this->nextInt(18, 40)), [], [self::LOINC_LACTATE, self::LOINC_PROCALCITONIN], [], $dx, 'lactate_result', [
                'lactate_mmol_l' => round($lactate, 1),
                'procalcitonin_ng_ml' => round($this->nextFloat(2.0, 18.0), 2),
                'elevated' => $lactate >= 2.0,
            ], $seq++);

            // Antibiotic timing — conformant <180min from recognition, else late.
            $abxOffset = $abxLate
                ? $this->nextInt(self::SEPSIS_ABX_TARGET_MIN + 25, self::SEPSIS_ABX_TARGET_MIN + 180)
                : $this->nextInt(35, self::SEPSIS_ABX_TARGET_MIN - 20);
            $abxAt = $t0->addMinutes($abxOffset);
            if ($abxLate) {
                $deviations[] = ['type' => 'antibiotic_late', 'minutes' => $abxOffset];
            }

            // Cultures should precede antibiotics; occasionally they don't.
            $cultureAt = $cultureAfterAbx ? $abxAt->addMinutes($this->nextInt(10, 45)) : $t0->addMinutes($this->nextInt(22, 40));
            if ($cultureAfterAbx) {
                $deviations[] = ['type' => 'culture_after_antibiotic'];
            }
            $this->writeEvent($ctx, 'order', $cultureAt, [self::LOINC_BLOOD_CULTURE], [], [], $dx, 'blood_culture_order', [
                'before_antibiotics' => ! $cultureAfterAbx,
            ], $seq++);

            $abx = $this->sepsisRegimen();
            $this->writeEvent($ctx, 'medication', $abxAt, [], [], $abx, $dx, 'antibiotic_administration', [
                'agents' => $abx,
                'minutes_from_recognition' => $abxOffset,
                'within_3hr' => $abxOffset <= self::SEPSIS_ABX_TARGET_MIN,
            ], $seq++);

            if ($hypotensive && ! $noFluidsWhenIndicated) {
                $this->writeEvent($ctx, 'medication', $t0->addMinutes($this->nextInt(20, 70)), [], [], [self::RX_NACL], $dx, 'fluid_bolus_30mlkg', [
                    'volume_ml_per_kg' => 30,
                ], $seq++);
            } elseif ($noFluidsWhenIndicated) {
                $deviations[] = ['type' => 'no_fluid_bolus_when_indicated'];
            }

            if ($shock) {
                $this->writeEvent($ctx, 'medication', $t0->addMinutes($this->nextInt(120, 240)), [], [], [self::RX_NOREPINEPHRINE], $dx, 'vasopressor_start', [
                    'agent' => 'norepinephrine',
                ], $seq++);
            }

            if (! $noRepeatLactate) {
                $repeatAt = $t0->addMinutes($this->nextInt(190, 350));
                $this->writeEvent($ctx, 'order', $repeatAt, [self::LOINC_LACTATE], [], [], $dx, 'repeat_lactate_order', [], $seq++);
                $this->writeEvent($ctx, 'observation', $repeatAt->addMinutes($this->nextInt(15, 35)), [], [self::LOINC_LACTATE], [], $dx, 'repeat_lactate_result', [
                    'lactate_mmol_l' => round(max(0.9, $lactate - $this->nextFloat(0.4, 2.2)), 1),
                ], $seq++);
            } else {
                $deviations[] = ['type' => 'no_repeat_lactate'];
            }

            $this->closeEncounter($ctx, $t0->addMinutes($this->nextInt(600, 5400)), 'sepsis', $deviations);
            $this->counts['sepsis']++;
        }
    }

    /** @return array<int,string> */
    private function sepsisRegimen(): array
    {
        // Antipseudomonal backbone for higher-risk sources; ceftriaxone where the
        // suspected source is lower-risk (urosepsis / community-acquired pneumonia).
        $backbone = $this->chance(70)
            ? $this->pick([self::RX_PIPTAZO, self::RX_CEFEPIME, self::RX_MEROPENEM])
            : self::RX_CEFTRIAXONE;

        // MRSA coverage added in ~60% of empiric regimens.
        return $this->chance(60) ? [$backbone, self::RX_VANCOMYCIN] : [$backbone];
    }

    // -- Acute ischemic stroke ----------------------------------------------

    private function seedStroke(): void
    {
        for ($i = 1; $i <= self::STROKE_COHORT; $i++) {
            $ctx = $this->openEncounter('STR', $i, 'stroke', 'neurosciences', 'E', 'emergency');
            $door = $this->randomOnset();
            $seq = 0;

            // ~18% present as hemorrhagic (thrombolysis contraindicated).
            $hemorrhagic = $this->chance(18);
            $dx = [$hemorrhagic ? self::DX_HEMORRHAGIC_STROKE : self::DX_ISCHEMIC_STROKE];

            $ctDelay = $this->chance(28);
            $needleDelay = $this->chance(26);
            $nihssMissing = $this->chance(10);
            $deviations = [];

            $this->writeEvent($ctx, 'movement', $door, [], [], [], $dx, 'ed_arrival', [
                'to_location' => 'ED-STROKE-BAY', 'trigger_event' => 'A04',
            ], $seq++);
            $this->writeEvent($ctx, 'clinical_context', $door->addMinutes($this->nextInt(1, 4)), [], [], [], $dx, 'stroke_alert', [
                'last_known_well_min' => $this->nextInt(30, 220),
            ], $seq++);

            if (! $nihssMissing) {
                $this->writeEvent($ctx, 'observation', $door->addMinutes($this->nextInt(5, 12)), [], [self::LOINC_NIHSS], [], $dx, 'nihss_assessment', [
                    'nihss_total' => $this->nextInt(3, 22),
                ], $seq++);
            } else {
                $deviations[] = ['type' => 'nihss_undocumented'];
            }

            $ctOrderAt = $door->addMinutes($this->nextInt(6, 12));
            $this->writeEvent($ctx, 'order', $ctOrderAt, [self::CT_HEAD], [], [], $dx, 'ct_head_order', [], $seq++);

            $doorToCt = $ctDelay
                ? $this->nextInt(self::STROKE_DOOR_TO_CT_MIN + 8, self::STROKE_DOOR_TO_CT_MIN + 45)
                : $this->nextInt(12, self::STROKE_DOOR_TO_CT_MIN - 2);
            $ctAt = $door->addMinutes($doorToCt);
            if ($ctDelay) {
                $deviations[] = ['type' => 'ct_delay', 'door_to_ct_min' => $doorToCt];
            }
            $this->writeEvent($ctx, 'observation', $ctAt, [], [self::RAD_IMPRESSION], [], $dx, 'ct_head_performed', [
                'door_to_ct_min' => $doorToCt,
            ], $seq++);
            $this->writeEvent($ctx, 'observation', $ctAt->addMinutes($this->nextInt(10, 22)), [], [self::RAD_IMPRESSION], [], $dx, 'ct_head_read', [
                'finding' => $hemorrhagic ? 'intracranial_hemorrhage' : 'no_hemorrhage',
            ], $seq++);

            if ($hemorrhagic) {
                $this->writeEvent($ctx, 'clinical_context', $ctAt->addMinutes($this->nextInt(12, 30)), [], [], [], $dx, 'thrombolysis_excluded', [
                    'reason' => 'intracranial_hemorrhage',
                ], $seq++);
            } else {
                // ~62% of ischemic strokes are thrombolysis-eligible.
                if ($this->chance(62)) {
                    $doorToNeedle = $needleDelay
                        ? $this->nextInt(self::STROKE_DOOR_TO_NEEDLE_MIN + 12, self::STROKE_DOOR_TO_NEEDLE_MIN + 70)
                        : $this->nextInt(38, self::STROKE_DOOR_TO_NEEDLE_MIN - 5);
                    if ($needleDelay) {
                        $deviations[] = ['type' => 'needle_delay', 'door_to_needle_min' => $doorToNeedle];
                    }
                    $agent = $this->chance(45) ? self::RX_TENECTEPLASE : self::RX_ALTEPLASE;
                    $this->writeEvent($ctx, 'medication', $door->addMinutes($doorToNeedle), [], [], [$agent], $dx, 'thrombolysis_administration', [
                        'agent' => $agent === self::RX_TENECTEPLASE ? 'tenecteplase' : 'alteplase',
                        'door_to_needle_min' => $doorToNeedle,
                        'within_60min' => $doorToNeedle <= self::STROKE_DOOR_TO_NEEDLE_MIN,
                    ], $seq++);
                } else {
                    $this->writeEvent($ctx, 'clinical_context', $ctAt->addMinutes($this->nextInt(15, 40)), [], [], [], $dx, 'thrombolysis_excluded', [
                        'reason' => $this->pick(['outside_window', 'rapidly_improving', 'minor_deficit', 'recent_surgery']),
                    ], $seq++);
                }
            }

            $this->closeEncounter($ctx, $door->addMinutes($this->nextInt(240, 2880)), 'stroke', $deviations);
            $this->counts['stroke']++;
        }
    }

    // -- Surgical safety (WHO checklist + phase timeline) --------------------

    private function seedSurgicalSafety(): void
    {
        $cases = DB::table('prod.or_cases')
            ->where('is_deleted', false)
            ->whereDate('surgery_date', '>=', $this->windowStart->toDateString())
            ->orderBy('case_id')
            ->get(['case_id', 'surgery_date', 'scheduled_start_time', 'scheduled_duration']);

        if ($cases->isEmpty()) {
            return;
        }

        $logs = DB::table('prod.or_logs')
            ->whereIn('case_id', $cases->pluck('case_id'))
            ->get()
            ->keyBy('case_id');

        foreach ($cases as $case) {
            $log = $logs->get($case->case_id);
            $this->seedCaseTimings($case, $log);
            $this->seedCaseMilestones($case, $log);
        }
    }

    private function seedCaseTimings(object $case, ?object $log): void
    {
        $orIn = $this->ts($log?->or_in_time) ?? $this->ts($case->scheduled_start_time);
        if ($orIn === null) {
            return;
        }
        $procStart = $this->ts($log?->procedure_start_time) ?? $orIn->addMinutes($this->nextInt(12, 25));
        $procEnd = $this->ts($log?->procedure_end_time) ?? $procStart->addMinutes((int) ($case->scheduled_duration ?: $this->nextInt(60, 180)));
        $orOut = $this->ts($log?->or_out_time) ?? $procEnd->addMinutes($this->nextInt(8, 18));
        $pacuIn = $this->ts($log?->pacu_in_time) ?? $orOut->addMinutes($this->nextInt(4, 12));
        $pacuOut = $this->ts($log?->pacu_out_time) ?? $pacuIn->addMinutes($this->nextInt(45, 130));

        $phases = [
            ['Pre_Procedure', $orIn->subMinutes(30), 30, $orIn->subMinutes($this->nextInt(22, 34)), $procStart->diffInMinutes($orIn)],
            ['Procedure', $procStart, max(1, $procStart->diffInMinutes($procEnd)), $procStart, max(1, $procStart->diffInMinutes($procEnd))],
            ['Recovery', $pacuIn, max(1, $pacuIn->diffInMinutes($pacuOut)), $pacuIn, max(1, $pacuIn->diffInMinutes($pacuOut))],
            ['Room_Turnover', $orOut, 30, $orOut, $this->nextInt(24, 52)],
        ];

        $rows = [];
        foreach ($phases as [$phase, $plannedStart, $plannedDur, $actualStart, $actualDur]) {
            $rows[] = [
                'case_id' => $case->case_id,
                'phase' => $phase,
                'planned_start' => $plannedStart,
                'planned_duration' => (int) $plannedDur,
                'actual_start' => $actualStart,
                'actual_duration' => (int) $actualDur,
                'variance' => (int) $actualDur - (int) $plannedDur,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }
        DB::table('prod.case_timings')->insert($rows);
        $this->counts['timings'] += count($rows);
    }

    private function seedCaseMilestones(object $case, ?object $log): void
    {
        $orIn = $this->ts($log?->or_in_time) ?? $this->ts($case->scheduled_start_time);
        if ($orIn === null) {
            return;
        }
        $procStart = $this->ts($log?->procedure_start_time) ?? $orIn->addMinutes($this->nextInt(12, 25));
        $orOut = $this->ts($log?->or_out_time) ?? $procStart->addMinutes((int) ($case->scheduled_duration ?: 120));
        $surgeryDay = CarbonImmutable::parse($case->surgery_date);

        // The WHO checklist steps are the deviation candidates.
        $skipTimeOut = $this->chance(4);
        $skipSignOut = $this->chance(3);

        $milestones = [
            ['H&P', 'Completed', true, $surgeryDay->subDay()->setTime(14, $this->nextInt(0, 59)), 'History & physical on file'],
            ['Consent', 'Completed', true, $orIn->subMinutes($this->nextInt(90, 180)), 'Informed consent verified'],
            ['Labs', 'Completed', true, $surgeryDay->subDay()->setTime(16, $this->nextInt(0, 59)), 'Pre-op labs resulted'],
            ['Safety_Check', $this->stepStatus(false), true, $orIn->subMinutes($this->nextInt(3, 8)), 'WHO checklist: Sign-In (before induction)'],
            ['Safety_Check', $this->stepStatus($skipTimeOut), true, $procStart->subMinutes($this->nextInt(1, 3)), 'WHO checklist: Time-Out (before incision)'],
            ['Safety_Check', $this->stepStatus($skipSignOut), true, $orOut->subMinutes($this->nextInt(1, 3)), 'WHO checklist: Sign-Out (before leaving OR)'],
            ['Transport', 'Completed', false, $orOut->addMinutes($this->nextInt(8, 20)), 'Transport to PACU'],
        ];

        $rows = [];
        $completed = 0;
        foreach ($milestones as [$type, $status, $required, $at, $note]) {
            $done = $status === 'Completed';
            $completed += $done ? 1 : 0;
            $rows[] = [
                'case_id' => $case->case_id,
                'milestone_type' => $type,
                'status' => $status,
                'required' => $required,
                'completed_at' => $done ? $at : null,
                'completed_by' => null,
                'notes' => $note,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }
        DB::table('prod.care_journey_milestones')->insert($rows);
        $this->counts['milestones'] += count($rows);

        if ($skipTimeOut || $skipSignOut) {
            $missing = [];
            if ($skipTimeOut) {
                $missing[] = 'Time-Out';
            }
            if ($skipSignOut) {
                $missing[] = 'Sign-Out';
            }
            DB::table('prod.case_safety_notes')->insert([
                'case_id' => $case->case_id,
                'note_type' => 'Safety_Alert',
                'content' => 'WHO Surgical Safety Checklist step(s) not documented: '.implode(', ', $missing).'.',
                'severity' => 'High',
                'created_by' => null,
                'acknowledged_by' => null,
                'acknowledged_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->counts['safety_notes']++;
        }

        $progress = (int) round($completed / count($milestones) * 100);
        DB::table('prod.or_cases')->where('case_id', $case->case_id)->update([
            'safety_status' => ($skipTimeOut || $skipSignOut) ? 'Alert' : 'Normal',
            'journey_progress' => $progress,
            'updated_at' => now(),
        ]);
    }

    private function stepStatus(bool $skipped): string
    {
        return $skipped ? 'Action_Required' : 'Completed';
    }

    /** @return array<int,int> */
    private function surgicalCohortCaseIds()
    {
        if (! Schema::hasTable('prod.or_cases')) {
            return collect();
        }

        return DB::table('prod.or_cases')
            ->where('is_deleted', false)
            ->whereDate('surgery_date', '>=', CarbonImmutable::now()->subDays(self::WINDOW_DAYS)->toDateString())
            ->pluck('case_id');
    }

    // -- flow_core writers ---------------------------------------------------

    /**
     * @return array<string,string> encounter context (patient/encounter refs, service line, classes)
     */
    private function openEncounter(string $tag, int $i, string $pathway, string $serviceLine, string $patientClass, string $fhirClass): array
    {
        $patientRef = $this->stableHash("CP-{$tag}-P{$i}");
        $encounterRef = $this->stableHash("CP-{$tag}-E{$i}");

        PatientIdentity::updateOrCreate(['patient_ref' => $patientRef], [
            'patient_display_ref' => 'PT-'.strtoupper(substr($patientRef, 0, 6)),
            'identifier_hash' => $this->stableHash("CP-{$tag}-P{$i}", 32),
            'deidentified' => true,
            'metadata' => ['seeder' => self::TAG, 'pathway' => $pathway],
        ]);

        FlowEncounter::updateOrCreate(['encounter_ref' => $encounterRef], [
            'patient_ref' => $patientRef,
            'patient_class' => $patientClass,
            'service_line' => $serviceLine,
            'encounter_status' => 'in-progress',
            'started_at' => null,
            'metadata' => ['seeder' => self::TAG, 'pathway' => $pathway],
        ]);

        return [
            'patient_ref' => $patientRef,
            'patient_display_ref' => 'PT-'.strtoupper(substr($patientRef, 0, 6)),
            'encounter_ref' => $encounterRef,
            'service_line' => $serviceLine,
            'patient_class' => $patientClass,
            'fhir_class' => $fhirClass,
            'tag' => $tag,
            'i' => (string) $i,
        ];
    }

    /**
     * @param  array<string,string>  $ctx
     * @param  array<int,string>  $deviations
     */
    private function closeEncounter(array $ctx, CarbonImmutable $endedAt, string $pathway, array $deviations): void
    {
        FlowEncounter::where('encounter_ref', $ctx['encounter_ref'])->update([
            'encounter_status' => 'finished',
            'ended_at' => $endedAt,
            'metadata' => [
                'seeder' => self::TAG,
                'pathway' => $pathway,
                'conformant' => $deviations === [],
                'deviations' => array_values(array_map(fn ($d) => $d['type'], $deviations)),
            ],
        ]);
    }

    /**
     * @param  array<string,string>  $ctx
     * @param  array<int,string>  $orderCodes
     * @param  array<int,string>  $observationCodes
     * @param  array<int,string>  $medicationCodes
     * @param  array<int,string>  $diagnosisCodes
     * @param  array<string,mixed>  $meta
     */
    private function writeEvent(
        array $ctx,
        string $category,
        CarbonImmutable $occurredAt,
        array $orderCodes,
        array $observationCodes,
        array $medicationCodes,
        array $diagnosisCodes,
        string $activity,
        array $meta,
        int $seq,
    ): void {
        $eventType = $category === 'movement' ? ($meta['trigger_event'] ?? 'A08') === 'A04' ? 'register' : 'update' : $category;

        FlowEvent::create([
            'flow_event_id' => sprintf('CP-%s-%04d-%02d', $ctx['tag'], (int) $ctx['i'], $seq),
            'event_category' => $category,
            'event_type' => $eventType,
            'patient_ref' => $ctx['patient_ref'],
            'patient_display_ref' => $ctx['patient_display_ref'],
            'encounter_ref' => $ctx['encounter_ref'],
            'occurred_at' => $occurredAt,
            'recorded_at' => $occurredAt->addSeconds($this->nextInt(2, 45)),
            'point_of_care' => $meta['to_location'] ?? null,
            'patient_class' => $ctx['patient_class'],
            'fhir_encounter_status' => 'in-progress',
            'fhir_encounter_class' => $ctx['fhir_class'],
            'service_line' => $ctx['service_line'],
            'diagnosis_codes' => $diagnosisCodes,
            'order_codes' => $orderCodes,
            'observation_codes' => $observationCodes,
            'medication_codes' => $medicationCodes,
            'source_protocol' => 'seeder',
            'deidentified' => true,
            'metadata' => array_merge([
                'seeder' => self::TAG,
                'pathway' => $ctx['tag'] === 'SEP' ? 'sepsis' : 'stroke',
                'activity' => $activity,
            ], $meta),
        ]);

        $this->counts['flow_events']++;
    }

    // -- Deterministic RNG (stable re-runs; matches CCDS convention) ---------

    private function nextInt(int $min, int $max): int
    {
        $this->rngState = ($this->rngState * 1103515245 + 12345) & 0x7FFFFFFF;

        return $min + (int) ($this->rngState % max(1, $max - $min + 1));
    }

    private function nextFloat(float $min, float $max): float
    {
        $this->rngState = ($this->rngState * 1103515245 + 12345) & 0x7FFFFFFF;

        return $min + ($this->rngState / 0x7FFFFFFF) * ($max - $min);
    }

    private function chance(int $pct): bool
    {
        return $this->nextInt(1, 100) <= $pct;
    }

    /**
     * @template T
     *
     * @param  array<int,T>  $items
     * @return T
     */
    private function pick(array $items)
    {
        return $items[$this->nextInt(0, count($items) - 1)];
    }

    private function randomOnset(): CarbonImmutable
    {
        return $this->windowStart->addMinutes($this->nextInt(0, self::WINDOW_DAYS * 24 * 60));
    }

    private function stableHash(string $value, int $len = 16): string
    {
        return substr(hash('sha256', $value), 0, $len);
    }

    private function ts(mixed $value): ?CarbonImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
