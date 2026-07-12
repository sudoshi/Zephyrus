<?php

namespace App\Services\Demo\Ancillary;

use App\Models\Ancillary\AncillaryOrder;
use App\Models\Radiology\Scanner;
use App\Models\Radiology\ScannerDowntime;
use App\Services\Demo\DemoClock;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

final class RadiologyDemoGenerator extends AbstractAncillaryDemoGenerator
{
    protected function department(): string
    {
        return 'rad';
    }

    protected function systemClass(): string
    {
        return 'ris';
    }

    protected function scenarios(DemoClock $clock): array
    {
        $scenarios = [];
        $durations = [35, 45, 57, 80, 108, 140, 182, 210, 240];
        foreach ($durations as $index => $duration) {
            $ordinal = $index + 1;
            $ordered = 390 + ($index * 4);
            $examEnd = $ordered - $duration;
            $events = [
                $this->event('RAD_ORDERED', $ordered),
                $this->event('RAD_PROTOCOLLED', $ordered - 5),
                $this->event('RAD_EXAM_START', $examEnd + 18, 'secondary'),
                $this->event('RAD_EXAM_END', $examEnd, 'secondary'),
                $this->event('RAD_IMAGES_AVAILABLE', max(1, $examEnd - 2)),
                $this->event('RAD_PRELIM', max(1, $examEnd - 10)),
                $this->event('RAD_FINAL', max(1, $examEnd - 20)),
            ];
            if ($ordinal === 5) {
                $events[] = $this->event('RAD_CRITICAL_NOTIFIED', max(1, $examEnd - 25));
                $events[] = $this->event('RAD_CRITICAL_ACKED', max(1, $examEnd - 30));
            }
            $scenarios[] = $this->scenario($clock, $ordinal, $ordered, $ordinal <= 3 ? 'stat' : 'urgent', 'emergency', $events, [
                'modality' => 'CT', 'procedure_code' => 'CT_ED', 'procedure_label' => 'ED CT',
                'scanner_key' => 'DEMO-CT-'.(($ordinal % 2) + 1), 'context' => 'ed',
                'shift' => in_array($ordinal, [8, 9], true) ? 'night_weekend' : 'day',
                'reference_order_to_complete_minutes' => $duration,
            ], $ordinal === 9);
        }

        $scenarios[] = $this->scenario($clock, 10, 180, 'routine', 'inpatient', [
            $this->event('RAD_ORDERED', 180), $this->event('RAD_TRANSPORT_REQUESTED', 165),
            $this->event('RAD_TRANSPORT_COMPLETE', 145), $this->event('RAD_EXAM_START', 130, 'secondary'),
            $this->event('RAD_EXAM_END', 100, 'secondary'), $this->event('RAD_PRELIM', 85), $this->event('RAD_FINAL', 70),
        ], ['modality' => 'CT', 'procedure_code' => 'CT_CHEST', 'procedure_label' => 'Discharge-pending chest CT', 'scanner_key' => 'DEMO-CT-1', 'context' => 'discharge', 'discharge_blocking' => true]);
        $scenarios[] = $this->scenario($clock, 11, 95, 'urgent', 'emergency', [
            $this->event('RAD_ORDERED', 95), $this->event('RAD_EXAM_START', 70, 'secondary'),
            $this->event('RAD_EXAM_END', 60, 'secondary'), $this->event('RAD_PRELIM', 45), $this->event('RAD_FINAL', 35),
        ], ['modality' => 'XR', 'procedure_code' => 'XR_CHEST', 'procedure_label' => 'Portable chest', 'is_portable' => true, 'context' => 'ed']);
        $scenarios[] = $this->scenario($clock, 12, 75, 'routine', 'outpatient', [
            $this->event('RAD_ORDERED', 75), $this->event('RAD_SCHEDULED', 70), $this->event('RAD_EXAM_END', 20, 'secondary'), $this->event('RAD_PRELIM', 12), $this->event('RAD_FINAL', 5),
        ], ['modality' => 'US', 'procedure_code' => 'US_ABD', 'procedure_label' => 'Abdominal ultrasound', 'scanner_key' => 'DEMO-US-1']);
        $scenarios[] = $this->scenario($clock, 13, 220, 'routine', 'outpatient', [
            $this->event('RAD_ORDERED', 220), $this->event('RAD_PRELIM', 75), $this->event('RAD_FINAL', 60),
        ], ['modality' => 'NM', 'procedure_code' => 'NM_HIDA', 'procedure_label' => 'HIDA scan', 'scanner_key' => 'DEMO-NM-1', 'shift' => 'night_weekend']);
        $scenarios[] = $this->scenario($clock, 14, 160, 'urgent', 'inpatient', [
            $this->event('RAD_ORDERED', 160), $this->event('RAD_PREP_COMPLETE', 140), $this->event('RAD_EXAM_START', 90, 'secondary'), $this->event('RAD_EXAM_END', 45, 'secondary'),
        ], ['modality' => 'IR', 'procedure_code' => 'IR_DRAIN', 'procedure_label' => 'Image-guided drainage', 'scanner_key' => 'DEMO-IR-1', 'is_ir' => true, 'or_case_id' => DB::table('prod.or_cases')->where('is_deleted', false)->orderBy('case_id')->value('case_id')]);
        $scenarios[] = $this->scenario($clock, 15, 140, 'routine', 'inpatient', [
            $this->event('RAD_ORDERED', 140), $this->event('RAD_EXAM_END', 75),
            $this->event('RAD_EXAM_END', 65, 'secondary'), $this->event('RAD_PRELIM', 45), $this->event('RAD_FINAL', 30),
            $this->event('RAD_FINAL', 20, 'primary', true, ['report_version' => 2]),
        ], ['modality' => 'CT', 'procedure_code' => 'CT_ABD', 'procedure_label' => 'CT abdomen with contrast', 'scanner_key' => 'DEMO-CT-2']);
        $scenarios[] = $this->scenario($clock, 16, 55, 'routine', 'inpatient', [
            $this->event('RAD_ORDERED', 55), $this->event('RAD_CANCELLED', 35),
        ], ['modality' => 'MRI', 'procedure_code' => 'MRI_SPINE', 'procedure_label' => 'MRI spine', 'scanner_key' => 'DEMO-MRI-1']);

        return $scenarios;
    }

    public function refresh(DemoClock $clock, string $owner): array
    {
        DB::table('prod.barriers')->where('demo_owner', $owner)->delete();
        Scanner::query()->where('demo_owner', $owner)->delete();

        $result = parent::refresh($clock, $owner);
        Scanner::query()->where('demo_owner', $owner)->whereDoesntHave('exams')->delete();
        $staffedHours = [
            'timezone' => (string) config('app.timezone', 'UTC'),
            'weekly' => [
                'monday' => [['start' => '06:00', 'end' => '23:00']],
                'tuesday' => [['start' => '06:00', 'end' => '23:00']],
                'wednesday' => [['start' => '06:00', 'end' => '23:00']],
                'thursday' => [['start' => '06:00', 'end' => '23:00']],
                'friday' => [['start' => '06:00', 'end' => '23:00']],
                'saturday' => [['start' => '07:00', 'end' => '19:00']],
                'sunday' => [['start' => '07:00', 'end' => '19:00']],
            ],
        ];
        Scanner::query()->where('demo_owner', $owner)->get()->each(function (Scanner $scanner) use ($staffedHours): void {
            $scanner->metadata = [
                ...$scanner->metadata,
                'staffed_operating_hours' => $staffedHours,
                'mpps_source_key' => 'demo.ancillary.rad.secondary',
            ];
            $scanner->save();
        });
        $scanner = Scanner::query()->where('demo_owner', $owner)->where('modality_code', 'CT')->orderBy('rad_scanner_id')->first();
        if ($scanner !== null) {
            ScannerDowntime::query()->create([
                'downtime_uuid' => Uuid::uuid5(Uuid::NAMESPACE_URL, 'zephyrus|radiology-demo-downtime|'.$clock->anchor()->toDateString())->toString(),
                'rad_scanner_id' => $scanner->rad_scanner_id, 'source_id' => $scanner->source_id,
                'source_downtime_key' => 'demo-downtime-'.$clock->anchor()->toDateString(), 'status' => 'active',
                'reason_code' => 'UNPLANNED_SERVICE', 'label' => 'Demo CT scanner service window',
                'starts_at' => $clock->anchor()->subMinutes(45), 'ends_at' => $clock->anchor()->addMinutes(30),
                'demo_owner' => $owner, 'metadata' => ['scenario' => 'night_weekend_degradation'],
            ]);
        }

        $barrierOrders = AncillaryOrder::query()->where('demo_owner', $owner)->where('department', 'rad')->whereNull('terminal_at')->whereNotNull('encounter_id')->orderBy('ancillary_order_id')->limit(2)->get();
        foreach ($barrierOrders as $index => $order) {
            $barrierId = DB::table('prod.barriers')->insertGetId([
                'encounter_id' => $order->encounter_id, 'unit_id' => $order->unit_id,
                'category' => $index === 0 ? 'logistical' : 'medical',
                'reason_code' => $index === 0 ? 'RAD_SCANNER_UNAVAILABLE' : 'RAD_READ_QUEUE',
                'description' => $index === 0 ? 'Demo scanner service window' : 'Demo interpretation queue delay',
                'owner' => 'Radiology operations', 'status' => 'open', 'opened_at' => $clock->anchor()->subMinutes(20),
                'demo_owner' => $owner, 'created_at' => now(), 'updated_at' => now(),
            ], 'barrier_id');
            DB::table('prod.ancillary_breaches')->where('ancillary_order_id', $order->ancillary_order_id)->where('status', 'open')->update(['barrier_id' => $barrierId]);
        }

        return [...$result,
            'exams' => DB::table('prod.rad_exams')->where('demo_owner', $owner)->count(),
            'reads' => DB::table('prod.rad_reads')->where('demo_owner', $owner)->count(),
            'scanners' => DB::table('prod.rad_scanners')->where('demo_owner', $owner)->count(),
            'downtimes' => DB::table('prod.rad_scanner_downtimes')->where('demo_owner', $owner)->count(),
            'criticalResults' => DB::table('prod.rad_critical_results')->where('demo_owner', $owner)->count(),
            'barriers' => DB::table('prod.barriers')->where('demo_owner', $owner)->count(),
        ];
    }
}
