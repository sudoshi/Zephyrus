<?php

namespace App\Services\Demo\Ancillary;

use App\Models\Lab\CriticalValue;
use App\Models\Lab\Result;
use App\Models\Lab\Specimen;
use App\Services\Demo\DemoClock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class LabDemoGenerator extends AbstractAncillaryDemoGenerator
{
    protected function department(): string
    {
        return 'lab';
    }

    protected function systemClass(): string
    {
        return 'lis';
    }

    protected function scenarios(DemoClock $clock): array
    {
        $date = $clock->anchor()->toDateString();
        $orCaseId = DB::table('prod.or_cases')
            ->where('is_deleted', false)
            ->orderByRaw('CASE WHEN surgery_date = ? THEN 0 ELSE 1 END', [$date])
            ->orderBy('scheduled_start_time')
            ->orderBy('case_id')
            ->value('case_id');
        $amAnchor = $this->amDrawAnchor($clock);
        $am = fn (int $orderOffset, int $elapsed = 0): int => $this->minutesAgo($clock, $amAnchor->addMinutes($orderOffset + $elapsed));
        $downtimeStarted = $amAnchor->addMinutes(40)->toIso8601String();
        $downtimeRestore = $amAnchor->addMinutes(135)->toIso8601String();

        return [
            $this->scenario($clock, 1, $am(0), 'timed', 'inpatient', [
                $this->e('LAB_ORDERED', $am(0)), $this->e('LAB_COLLECTED', $am(0, 15)),
                $this->e('LAB_IN_TRANSIT', $am(0, 20)), $this->e('LAB_RECEIVED', $am(0, 30)),
                $this->e('LAB_ANALYSIS_STARTED', $am(0, 40)),
                $this->e('LAB_RESULTED', $am(0, 62), $this->result('BMP-01', '1', 'final', 'BMP', ['auto_verified' => true, 'verified_at' => $clock->anchor()->subMinutes($am(0, 62))->toIso8601String(), 'analyzer_ref' => 'DEMO-CHEM-1'])),
                $this->e('LAB_VERIFIED', $am(0, 62), $this->result('BMP-01', '1', 'final', 'BMP', ['auto_verified' => true, 'verified_at' => $clock->anchor()->subMinutes($am(0, 62))->toIso8601String(), 'analyzer_ref' => 'DEMO-CHEM-1'])),
            ], $this->meta($date, 'AM-01', 'metabolic_panel', 'BMP', 'none', ['shift' => 'am_draw'])),
            $this->scenario($clock, 2, $am(5), 'timed', 'inpatient', [
                $this->e('LAB_ORDERED', $am(5)), $this->e('LAB_COLLECTED', $am(5, 15)),
                $this->e('LAB_IN_TRANSIT', $am(5, 23)), $this->e('LAB_RECEIVED', $am(5, 33)),
                $this->e('LAB_ANALYSIS_STARTED', $am(5, 45)),
                $this->e('LAB_RESULTED', $am(5, 71), $this->result('CBC-02', '1', 'final', 'CBC', ['analyzer_ref' => 'DEMO-HEME-1'])),
                $this->e('LAB_VERIFIED', $am(5, 75), $this->result('CBC-02', '1', 'final', 'CBC', ['verified_at' => $clock->anchor()->subMinutes($am(5, 75))->toIso8601String(), 'analyzer_ref' => 'DEMO-HEME-1'])),
            ], $this->meta($date, 'AM-02', 'blood_count', 'CBC', 'none', ['shift' => 'am_draw'])),
            $this->scenario($clock, 3, $am(10), 'timed', 'inpatient', [
                $this->e('LAB_ORDERED', $am(10)), $this->e('LAB_COLLECTED', $am(10, 15)),
                $this->e('LAB_IN_TRANSIT', $am(10, 20)), $this->e('LAB_RECEIVED', $am(10, 35)),
                $this->e('LAB_RESULTED', $am(10, 75), $this->result('BMP-03', '1', 'final', 'BMP', ['analyzer_ref' => 'DEMO-CHEM-1'])),
                $this->e('LAB_VERIFIED', $am(10, 80), $this->result('BMP-03', '1', 'final', 'BMP', ['verified_at' => $clock->anchor()->subMinutes($am(10, 80))->toIso8601String(), 'analyzer_ref' => 'DEMO-CHEM-1'])),
            ], $this->meta($date, 'AM-03', 'metabolic_panel', 'BMP', 'none', ['shift' => 'am_draw'])),
            $this->scenario($clock, 4, $am(15), 'timed', 'inpatient', [
                $this->e('LAB_ORDERED', $am(15)),
            ], $this->meta($date, 'AM-04', 'blood_count', 'CBC', 'none', ['shift' => 'am_draw', 'queue_state' => 'collection_pending'])),
            $this->scenario($clock, 5, $am(20), 'timed', 'inpatient', [
                $this->e('LAB_ORDERED', $am(20)), $this->e('LAB_COLLECTED', $am(20, 15)),
                $this->e('LAB_IN_TRANSIT', $am(20, 30)), $this->e('LAB_RECEIVED', $am(20, 45)),
                $this->e('LAB_ANALYSIS_STARTED', $am(20, 80)),
                $this->e('LAB_RESULTED', $am(20, 110), $this->result('BMP-05', '1', 'final', 'BMP', [
                    'analyzer_ref' => 'DEMO-CHEM-2', 'analyzer_operational_state' => 'rerouted_during_downtime',
                    'analyzer_downtime_started_at' => $downtimeStarted, 'analyzer_expected_restore_at' => $downtimeRestore,
                ])),
                $this->e('LAB_VERIFIED', $am(20, 115), $this->result('BMP-05', '1', 'final', 'BMP', [
                    'verified_at' => $clock->anchor()->subMinutes($am(20, 115))->toIso8601String(), 'analyzer_ref' => 'DEMO-CHEM-2',
                    'analyzer_operational_state' => 'rerouted_during_downtime',
                    'analyzer_downtime_started_at' => $downtimeStarted, 'analyzer_expected_restore_at' => $downtimeRestore,
                ])),
            ], $this->meta($date, 'AM-05', 'metabolic_panel', 'BMP', 'none', [
                'shift' => 'am_draw', 'analyzer_operational_state' => 'rerouted_during_downtime',
                'analyzer_downtime_started_at' => $downtimeStarted, 'analyzer_expected_restore_at' => $downtimeRestore,
            ])),
            $this->scenario($clock, 6, $am(25), 'timed', 'inpatient', [
                $this->e('LAB_ORDERED', $am(25)), $this->e('LAB_COLLECTED', $am(25, 15)),
                $this->e('LAB_IN_TRANSIT', $am(25, 20)), $this->e('LAB_REJECTED', $am(25, 45), ['rejection_reason_code' => 'CLOTTED']),
                $this->e('LAB_RECOLLECT_ORDERED', $am(25, 50), ['source_specimen_key' => "demo:{$date}:lab:AM-06-R", 'parent_source_specimen_key' => "demo:{$date}:lab:AM-06"]),
                $this->e('LAB_COLLECTED', $am(25, 70), ['source_specimen_key' => "demo:{$date}:lab:AM-06-R"]),
                $this->e('LAB_IN_TRANSIT', $am(25, 75), ['source_specimen_key' => "demo:{$date}:lab:AM-06-R"]),
                $this->e('LAB_RECEIVED', $am(25, 85), ['source_specimen_key' => "demo:{$date}:lab:AM-06-R"]),
                $this->e('LAB_RESULTED', $am(25, 125), [...$this->result('CBC-06', '1', 'final', 'CBC', ['analyzer_ref' => 'DEMO-HEME-1']), 'source_specimen_key' => "demo:{$date}:lab:AM-06-R"]),
                $this->e('LAB_VERIFIED', $am(25, 130), [...$this->result('CBC-06', '1', 'final', 'CBC', ['verified_at' => $clock->anchor()->subMinutes($am(25, 130))->toIso8601String(), 'analyzer_ref' => 'DEMO-HEME-1']), 'source_specimen_key' => "demo:{$date}:lab:AM-06-R"]),
            ], $this->meta($date, 'AM-06', 'blood_count', 'CBC', 'none', ['shift' => 'am_draw'])),
            $this->scenario($clock, 7, 85, 'stat', 'emergency', [
                $this->e('LAB_ORDERED', 85), $this->e('LAB_COLLECTED', 80),
                $this->e('LAB_IN_TRANSIT', 78), $this->e('LAB_RECEIVED', 74),
                $this->e('LAB_ANALYSIS_STARTED', 70),
                $this->e('LAB_PRELIM', 62, $this->result('TROP-07', '1', 'preliminary', 'TROPONIN_I', ['is_critical' => true, 'abnormal_flag' => 'critical', 'analyzer_ref' => 'DEMO-CHEM-1'])),
            ], $this->meta($date, 'ED-07', 'troponin', 'TROPONIN_I', 'ed_disposition', [
                'context' => 'ed', 'decision_explanation' => 'ED disposition is blocked until the critical troponin is verified and its callback loop is acknowledged.',
            ]), true),
            $this->scenario($clock, 8, 70, 'stat', 'emergency', [
                $this->e('LAB_ORDERED', 70), $this->e('LAB_COLLECTED', 66),
                $this->e('LAB_IN_TRANSIT', 64), $this->e('LAB_RECEIVED', 60),
                $this->e('LAB_RESULTED', 42, $this->result('TROP-08', '1', 'final', 'TROPONIN_I', ['is_critical' => true, 'abnormal_flag' => 'critical', 'analyzer_ref' => 'DEMO-CHEM-1'])),
                $this->e('LAB_VERIFIED', 40, $this->result('TROP-08', '1', 'final', 'TROPONIN_I', ['is_critical' => true, 'abnormal_flag' => 'critical', 'verified_at' => $clock->anchor()->subMinutes(40)->toIso8601String(), 'analyzer_ref' => 'DEMO-CHEM-1'])),
                $this->e('LAB_CRITICAL_NOTIFIED', 35, ['source_result_key' => 'TROP-08', 'source_result_version' => '1', 'notified_at' => $clock->anchor()->subMinutes(35)->toIso8601String(), 'recipient_role' => 'emergency_physician']),
                $this->e('LAB_CRITICAL_ACKED', 30, ['source_result_key' => 'TROP-08', 'source_result_version' => '1', 'acknowledged_at' => $clock->anchor()->subMinutes(30)->toIso8601String(), 'recipient_role' => 'emergency_physician']),
            ], $this->meta($date, 'ED-08', 'troponin', 'TROPONIN_I', 'ed_disposition', ['context' => 'ed'])),
            $this->scenario($clock, 9, 65, 'routine', 'emergency', [
                $this->e('LAB_ORDERED', 65), $this->e('LAB_COLLECTED', 55),
                $this->e('LAB_IN_TRANSIT', 52), $this->e('LAB_RECEIVED', 48),
                $this->e('LAB_RESULTED', 20, $this->result('BMP-09', '1', 'final', 'BMP', ['auto_verified' => true, 'verified_at' => $clock->anchor()->subMinutes(20)->toIso8601String(), 'analyzer_ref' => 'DEMO-CHEM-1'])),
                $this->e('LAB_VERIFIED', 20, $this->result('BMP-09', '1', 'final', 'BMP', ['auto_verified' => true, 'verified_at' => $clock->anchor()->subMinutes(20)->toIso8601String(), 'analyzer_ref' => 'DEMO-CHEM-1'])),
            ], $this->meta($date, 'ED-09', 'metabolic_panel', 'BMP', 'discharge_gate', ['context' => 'ed'])),
            $this->scenario($clock, 10, 45, 'routine', 'emergency', [
                $this->e('LAB_ORDERED', 45), $this->e('LAB_COLLECTED', 35), $this->e('LAB_IN_TRANSIT', 30),
            ], $this->meta($date, 'ED-10', 'blood_count', 'CBC', 'none', ['context' => 'ed', 'queue_state' => 'transport_pending'])),
            $this->scenario($clock, 11, 75, 'urgent', 'inpatient', [
                $this->e('LAB_ORDERED', 75), $this->e('LAB_COLLECTED', 68),
                $this->e('LAB_IN_TRANSIT', 62), $this->e('LAB_RECEIVED', 58),
                $this->e('LAB_PRELIM', 25, $this->result('BMP-11', '1', 'preliminary', 'BMP', ['analyzer_ref' => 'DEMO-CHEM-1'])),
            ], $this->meta($date, 'DC-11', 'metabolic_panel', 'BMP', 'discharge_gate', [
                'context' => 'discharge', 'discharge_blocking' => true,
                'decision_explanation' => 'Discharge medication reconciliation is blocked until the metabolic panel is verified.',
            ])),
            $this->scenario($clock, 12, 55, 'stat', 'perioperative', [
                $this->e('LAB_ORDERED', 55), $this->e('LAB_COLLECTED', 50),
                $this->e('LAB_IN_TRANSIT', 47), $this->e('LAB_RECEIVED', 44),
                $this->e('LAB_PRELIM', 15, $this->result('INR-12', '1', 'preliminary', 'PT_INR', ['analyzer_ref' => 'DEMO-COAG-1'])),
            ], $this->meta($date, 'OR-12', 'coagulation', 'PT_INR', 'or_gate', [
                'or_case_id' => $orCaseId,
                'decision_explanation' => 'Operating-room start readiness is blocked until the coagulation result is verified.',
            ])),
            $this->scenario($clock, 13, 60, 'routine', 'inpatient', [
                $this->e('LAB_ORDERED', 60), $this->e('LAB_COLLECTED', 55),
                $this->e('LAB_REJECTED', 48, ['rejection_reason_code' => 'HEMOLYZED']),
                $this->e('LAB_RECOLLECT_ORDERED', 45, ['source_specimen_key' => "demo:{$date}:lab:RW-13-R", 'parent_source_specimen_key' => "demo:{$date}:lab:RW-13"]),
            ], $this->meta($date, 'RW-13', 'metabolic_panel', 'BMP', 'none', ['queue_state' => 'recollect_pending'])),
            $this->scenario($clock, 14, 6000, 'routine', 'inpatient', [
                $this->e('LAB_ORDERED', 6000), $this->e('LAB_COLLECTED', 5990),
                $this->e('LAB_IN_TRANSIT', 5980), $this->e('LAB_RECEIVED', 5960),
                $this->e('LAB_PRELIM', 4500, $this->result('BC-14', '1', 'preliminary', 'BLOOD_CULTURE', ['result_stage' => 'preliminary', 'analyzer_ref' => 'DEMO-MICRO-1'])),
                $this->e('LAB_PRELIM', 3200, $this->result('BC-14', '2', 'preliminary', 'BLOOD_CULTURE', ['result_stage' => 'organism_identification', 'analyzer_ref' => 'DEMO-MICRO-1'])),
                $this->e('LAB_PRELIM', 2200, $this->result('BC-14', '3', 'preliminary', 'BLOOD_CULTURE', ['result_stage' => 'susceptibility', 'analyzer_ref' => 'DEMO-MICRO-1'])),
                $this->e('LAB_RESULTED', 1500, $this->result('BC-14', '4', 'final', 'BLOOD_CULTURE', ['result_stage' => 'final', 'analyzer_ref' => 'DEMO-MICRO-1'])),
                $this->e('LAB_VERIFIED', 1490, $this->result('BC-14', '4', 'final', 'BLOOD_CULTURE', ['result_stage' => 'final', 'verified_at' => $clock->anchor()->subMinutes(1490)->toIso8601String(), 'analyzer_ref' => 'DEMO-MICRO-1'])),
            ], $this->meta($date, 'MICRO-14', 'culture', 'BLOOD_CULTURE', 'none', ['operational_window' => 'historical_study_only'])),
        ];
    }

    public function refresh(DemoClock $clock, string $owner): array
    {
        return DB::transaction(function () use ($clock, $owner): array {
            CriticalValue::query()->where('demo_owner', $owner)->delete();
            Result::query()->where('demo_owner', $owner)->delete();
            Specimen::query()->where('demo_owner', $owner)->whereNotNull('parent_specimen_id')->delete();
            Specimen::query()->where('demo_owner', $owner)->delete();

            $result = parent::refresh($clock, $owner);

            return [...$result,
                'specimens' => Specimen::query()->where('demo_owner', $owner)->count(),
                'results' => Result::query()->where('demo_owner', $owner)->count(),
                'criticalValues' => CriticalValue::query()->where('demo_owner', $owner)->count(),
                'decisionPending' => Result::query()
                    ->where('demo_owner', $owner)
                    ->whereNull('verified_at')
                    ->whereHas('testCatalog', fn ($query) => $query->where('decision_class', '!=', 'none'))
                    ->count(),
            ];
        }, 3);
    }

    /** @return array<string, mixed> */
    private function e(string $code, int $minutesAgo, array $attributes = []): array
    {
        return $this->event($code, $minutesAgo, 'primary', false, $attributes);
    }

    /** @return array<string, mixed> */
    private function result(string $key, string $version, string $status, string $testCode, array $attributes = []): array
    {
        return [
            'source_result_key' => $key,
            'source_result_version' => $version,
            'result_status' => $status,
            'test_code' => $testCode,
            ...$attributes,
        ];
    }

    /** @return array<string, mixed> */
    private function meta(string $date, string $specimenSuffix, string $testFamily, string $testCode, string $decisionClass, array $attributes = []): array
    {
        return [
            'source_specimen_key' => "demo:{$date}:lab:{$specimenSuffix}",
            'source_accession_key' => "demo-accession:{$date}:{$specimenSuffix}",
            'specimen_type' => match ($testCode) {
                'TROPONIN_I' => 'plasma', 'CBC' => 'whole_blood', 'PT_INR' => 'citrated_plasma',
                'BLOOD_CULTURE' => 'blood_culture_set', default => 'serum',
            },
            'test_family' => $testFamily,
            'test_code' => $testCode,
            'decision_class' => $decisionClass,
            ...$attributes,
        ];
    }

    private function amDrawAnchor(DemoClock $clock): CarbonImmutable
    {
        $local = $clock->anchor()->setTimezone((string) config('app.timezone', 'UTC'));
        $anchor = $local->startOfDay()->addHours(3);
        if ($local->lessThan($anchor)) {
            $anchor = $anchor->subDay();
        }

        return $anchor;
    }

    private function minutesAgo(DemoClock $clock, CarbonImmutable $at): int
    {
        return max(0, (int) round($at->diffInSeconds($clock->anchor(), false) / 60));
    }
}
