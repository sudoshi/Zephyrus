<?php

namespace App\Services\Demo\Ancillary;

use App\Models\Lab\AnatomicPathologyCase;
use App\Services\Demo\DemoClock;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use RuntimeException;

final class PathologyDemoGenerator extends AbstractAncillaryDemoGenerator
{
    protected function department(): string
    {
        return 'pathology';
    }

    protected function systemClass(): string
    {
        return 'ap_lis';
    }

    protected function scenarios(DemoClock $clock): array
    {
        $caseIds = $this->caseIds($clock);

        return [
            $this->scenario($clock, 1, 1800, 'routine', 'perioperative', [
                $this->event('AP_SPECIMEN_OUT', 1740), $this->event('AP_RECEIVED', 1720),
                $this->event('AP_GROSSED', 1600), $this->event('AP_PROCESSING_BATCH', 1200),
            ], $this->meta($caseIds[0], 'overnight_processing')),
            $this->scenario($clock, 2, 1700, 'routine', 'perioperative', [
                $this->event('AP_SPECIMEN_OUT', 1660), $this->event('AP_RECEIVED', 1640),
                $this->event('AP_GROSSED', 1500), $this->event('AP_PROCESSING_BATCH', 1100),
                $this->event('AP_SLIDES_READY', 500),
            ], $this->meta($caseIds[1], 'overnight_slides_ready')),
            $this->scenario($clock, 3, 1600, 'routine', 'perioperative', [
                $this->event('AP_SPECIMEN_OUT', 1560), $this->event('AP_RECEIVED', 1540),
                $this->event('AP_GROSSED', 1400), $this->event('AP_PROCESSING_BATCH', 1000),
                $this->event('AP_SLIDES_READY', 420), $this->event('AP_DIAGNOSED', 100),
            ], $this->meta($caseIds[2], 'overnight_diagnosed')),
            $this->scenario($clock, 4, 3000, 'routine', 'perioperative', [
                $this->event('AP_SPECIMEN_OUT', 2950), $this->event('AP_RECEIVED', 2930),
                $this->event('AP_GROSSED', 2800), $this->event('AP_PROCESSING_BATCH', 2400),
                $this->event('AP_SLIDES_READY', 1800), $this->event('AP_DIAGNOSED', 1000),
                $this->event('AP_SIGNED_OUT', 800),
            ], $this->meta($caseIds[3], 'historical_signed_out', ['operational_window' => 'historical_study_only'])),
            $this->scenario($clock, 5, 50, 'stat', 'perioperative', [
                $this->event('AP_SPECIMEN_OUT', 25), $this->event('AP_RECEIVED', 22),
                $this->event('AP_FROZEN_STARTED', 12),
            ], $this->meta($caseIds[4], 'live_frozen_in_progress', [
                'decision_explanation' => 'The linked operating-room procedure is blocked until the frozen-section interpretation is communicated.',
            ]), true),
            $this->scenario($clock, 6, 80, 'stat', 'perioperative', [
                $this->event('AP_SPECIMEN_OUT', 60), $this->event('AP_RECEIVED', 55),
                $this->event('AP_FROZEN_STARTED', 30), $this->event('AP_FROZEN_RESULTED', 12),
            ], $this->meta($caseIds[5], 'live_frozen_resulted')),
        ];
    }

    public function refresh(DemoClock $clock, string $owner): array
    {
        return DB::transaction(function () use ($clock, $owner): array {
            AnatomicPathologyCase::query()->where('demo_owner', $owner)->delete();
            $result = parent::refresh($clock, $owner);
            $orders = DB::table('prod.ancillary_orders')
                ->where('demo_owner', $owner)->where('department', 'pathology')
                ->orderBy('source_order_key')->get()->values();
            if ($orders->count() !== 6) {
                throw new RuntimeException('Pathology demo requires exactly six owned shared orders.');
            }
            $caseIds = $this->caseIds($clock);
            $rows = $this->satelliteRows($clock);
            $cohorts = ['routine', 'complex', 'consult_send_out', 'routine', 'frozen_section', 'frozen_section'];
            $procedureCodes = ['SURG_PATH_ROUTINE', 'SURG_PATH_COMPLEX', 'AP_CONSULT_SEND_OUT', 'SURG_PATH_ROUTINE', 'FROZEN_SECTION', 'FROZEN_SECTION'];
            $procedureLabels = ['Routine surgical pathology', 'Complex surgical pathology', 'Consult / send-out pathology', 'Routine surgical pathology', 'Intraoperative frozen section', 'Intraoperative frozen section'];
            foreach ($rows as $index => $row) {
                $order = $orders[$index];
                AnatomicPathologyCase::query()->create([
                    'ap_case_uuid' => $this->uuid($clock, 'ap', $index + 1),
                    'ancillary_order_id' => $order->ancillary_order_id,
                    'source_id' => $order->source_id,
                    'source_case_key' => 'demo-ap-'.$clock->anchor()->toDateString().'-'.str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT),
                    'case_id' => $caseIds[$index],
                    'encounter_id' => $order->encounter_id,
                    'source_accession_key' => 'demo-ap-accession-'.$clock->anchor()->toDateString().'-'.($index + 1),
                    'specimen_ref' => 'demo-ap-specimen-'.($index + 1),
                    'procedure_code' => $procedureCodes[$index],
                    'procedure_label' => $procedureLabels[$index],
                    'case_type' => $index >= 4 ? 'frozen_section' : ($index === 2 ? 'other' : 'surgical'),
                    ...$row,
                    'pathologist_ref' => in_array($row['stage'], ['diagnosed', 'signed_out'], true) || $row['frozen_status'] === 'resulted' ? 'demo-pathologist-on-call' : null,
                    'demo_owner' => $owner,
                    'metadata' => [
                        'cohort' => $index < 4 ? 'overnight_batch' : 'live_frozen_section',
                        'work_cohort' => $cohorts[$index],
                        'processing_model' => $index < 4
                            ? ($index === 2 ? 'consult_send_out_with_overnight_batch' : 'overnight_batch')
                            : 'intraoperative',
                        'single_block' => $index >= 4,
                        'operational_window' => $index === 3 ? 'historical_study_only' : 'current',
                        'decision_context' => $index === 4 ? [
                            'decision_class' => 'or_gate', 'blocked_object_type' => 'or_case',
                            'blocked_object_id' => $caseIds[$index],
                            'explanation' => 'The linked operating-room procedure is blocked until the frozen-section interpretation is communicated.',
                        ] : null,
                    ],
                ]);
            }

            return [...$result,
                'apCases' => AnatomicPathologyCase::query()->where('demo_owner', $owner)->count(),
                'frozenSections' => AnatomicPathologyCase::query()->where('demo_owner', $owner)->frozen()->count(),
            ];
        }, 3);
    }

    /** @return list<int> */
    private function caseIds(DemoClock $clock): array
    {
        $operatingDate = DB::table('prod.or_cases')->where('is_deleted', false)
            ->whereDate('surgery_date', '<=', $clock->anchor()->toDateString())->max('surgery_date')
            ?? DB::table('prod.or_cases')->where('is_deleted', false)->min('surgery_date');
        $all = DB::table('prod.or_cases')->where('is_deleted', false)->whereDate('surgery_date', $operatingDate)
            ->orderBy('scheduled_start_time')->orderBy('case_id')->pluck('case_id')->map(fn ($id): int => (int) $id)->all();
        $ids = array_slice($all, 0, 4);
        if ($ids === []) {
            throw new RuntimeException('Pathology demo requires at least one current OR case.');
        }
        while (count($ids) < 4) {
            $ids[] = $ids[count($ids) % count($ids)];
        }
        $procedureStart = min(count($all) - 1, max(0, (int) ceil(count($all) * 0.30)));
        $procedureEnd = min(count($all) - 1, max($procedureStart, (int) ceil(count($all) * 0.75) - 1));
        $ids[] = $all[$procedureStart];
        $ids[] = $all[min($procedureEnd, $procedureStart + 1)];

        return $ids;
    }

    /** @return array<string, mixed> */
    private function meta(int $caseId, string $cohort, array $extra = []): array
    {
        return ['or_case_id' => $caseId, 'cohort' => $cohort, ...$extra];
    }

    /** @return list<array<string, mixed>> */
    private function satelliteRows(DemoClock $clock): array
    {
        $a = $clock->anchor();

        return [
            ['stage' => 'processing', 'current_stage_at' => $a->subMinutes(1200), 'specimen_out_at' => $a->subMinutes(1740), 'received_at' => $a->subMinutes(1720), 'grossed_at' => $a->subMinutes(1600), 'processing_batch_at' => $a->subMinutes(1200), 'slides_ready_at' => null, 'diagnosed_at' => null, 'signed_out_at' => null, 'frozen_status' => 'not_applicable', 'frozen_started_at' => null, 'frozen_resulted_at' => null],
            ['stage' => 'slides_ready', 'current_stage_at' => $a->subMinutes(500), 'specimen_out_at' => $a->subMinutes(1660), 'received_at' => $a->subMinutes(1640), 'grossed_at' => $a->subMinutes(1500), 'processing_batch_at' => $a->subMinutes(1100), 'slides_ready_at' => $a->subMinutes(500), 'diagnosed_at' => null, 'signed_out_at' => null, 'frozen_status' => 'not_applicable', 'frozen_started_at' => null, 'frozen_resulted_at' => null],
            ['stage' => 'diagnosed', 'current_stage_at' => $a->subMinutes(100), 'specimen_out_at' => $a->subMinutes(1560), 'received_at' => $a->subMinutes(1540), 'grossed_at' => $a->subMinutes(1400), 'processing_batch_at' => $a->subMinutes(1000), 'slides_ready_at' => $a->subMinutes(420), 'diagnosed_at' => $a->subMinutes(100), 'signed_out_at' => null, 'frozen_status' => 'not_applicable', 'frozen_started_at' => null, 'frozen_resulted_at' => null],
            ['stage' => 'signed_out', 'current_stage_at' => $a->subMinutes(800), 'specimen_out_at' => $a->subMinutes(2950), 'received_at' => $a->subMinutes(2930), 'grossed_at' => $a->subMinutes(2800), 'processing_batch_at' => $a->subMinutes(2400), 'slides_ready_at' => $a->subMinutes(1800), 'diagnosed_at' => $a->subMinutes(1000), 'signed_out_at' => $a->subMinutes(800), 'frozen_status' => 'not_applicable', 'frozen_started_at' => null, 'frozen_resulted_at' => null],
            ['stage' => 'received', 'current_stage_at' => $a->subMinutes(12), 'specimen_out_at' => $a->subMinutes(25), 'received_at' => $a->subMinutes(22), 'grossed_at' => null, 'processing_batch_at' => null, 'slides_ready_at' => null, 'diagnosed_at' => null, 'signed_out_at' => null, 'frozen_status' => 'in_progress', 'frozen_started_at' => $a->subMinutes(12), 'frozen_resulted_at' => null],
            ['stage' => 'diagnosed', 'current_stage_at' => $a->subMinutes(12), 'specimen_out_at' => $a->subMinutes(60), 'received_at' => $a->subMinutes(55), 'grossed_at' => null, 'processing_batch_at' => null, 'slides_ready_at' => null, 'diagnosed_at' => $a->subMinutes(12), 'signed_out_at' => null, 'frozen_status' => 'resulted', 'frozen_started_at' => $a->subMinutes(30), 'frozen_resulted_at' => $a->subMinutes(12)],
        ];
    }

    private function uuid(DemoClock $clock, string $type, int $ordinal): string
    {
        return Uuid::uuid5(Uuid::NAMESPACE_URL, "zephyrus|ancillary-demo|{$type}|{$clock->anchor()->toDateString()}|{$ordinal}")->toString();
    }
}
