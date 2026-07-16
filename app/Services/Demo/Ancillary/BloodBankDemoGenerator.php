<?php

namespace App\Services\Demo\Ancillary;

use App\Models\Lab\BloodBankReadiness;
use App\Services\Demo\DemoClock;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use RuntimeException;

final class BloodBankDemoGenerator extends AbstractAncillaryDemoGenerator
{
    protected function department(): string
    {
        return 'blood_bank';
    }

    protected function systemClass(): string
    {
        return 'blood_bank';
    }

    protected function scenarios(DemoClock $clock): array
    {
        $cases = $this->cases($clock);

        return [
            $this->scenario($clock, 1, 90, 'stat', 'perioperative', [$this->event('BB_ORDERED', 90)], $this->meta($cases[0], 'ordered', 'OR start is blocked until type-and-screen testing begins.'), true),
            $this->scenario($clock, 2, 120, 'stat', 'perioperative', [$this->event('BB_ORDERED', 120)], $this->meta($cases[1], 'testing', 'OR start is blocked while type-and-screen compatibility testing is in progress.')),
            $this->scenario($clock, 3, 160, 'stat', 'perioperative', [$this->event('BB_ORDERED', 160), $this->event('BB_TNS_READY', 80)], $this->meta($cases[2], 'type_screen_ready', 'OR start is blocked until requested units are crossmatch ready.')),
            $this->scenario($clock, 4, 210, 'stat', 'perioperative', [$this->event('BB_ORDERED', 210), $this->event('BB_TNS_READY', 150), $this->event('BB_CROSSMATCH_READY', 70)], $this->meta($cases[3], 'crossmatch_ready')),
            $this->scenario($clock, 5, 260, 'stat', 'perioperative', [$this->event('BB_ORDERED', 260), $this->event('BB_TNS_READY', 210), $this->event('BB_CROSSMATCH_READY', 150), $this->event('BB_UNIT_ISSUED', 30)], $this->meta($cases[4], 'issued')),
            $this->scenario($clock, 6, 35, 'stat', 'perioperative', [$this->event('BB_ORDERED', 35), $this->event('BB_MTP_ACTIVATED', 10)], $this->meta($cases[5], 'mtp_active', 'The active massive-transfusion response is blocked on continuous blood-product allocation.')),
        ];
    }

    public function refresh(DemoClock $clock, string $owner): array
    {
        return DB::transaction(function () use ($clock, $owner): array {
            BloodBankReadiness::query()->where('demo_owner', $owner)->delete();
            $result = parent::refresh($clock, $owner);
            $orders = DB::table('prod.ancillary_orders')->where('demo_owner', $owner)
                ->where('department', 'blood_bank')->orderBy('source_order_key')->get()->values();
            if ($orders->count() !== 6) {
                throw new RuntimeException('Blood Bank demo requires exactly six owned shared orders.');
            }
            $cases = $this->cases($clock);
            $a = $clock->anchor();
            $states = [
                ['readiness_state' => 'ordered', 'type_screen_state' => 'pending', 'crossmatch_state' => 'pending', 'units_requested' => 2, 'units_allocated' => 0, 'units_issued' => 0],
                ['readiness_state' => 'testing', 'type_screen_state' => 'pending', 'crossmatch_state' => 'pending', 'units_requested' => 2, 'units_allocated' => 0, 'units_issued' => 0],
                ['readiness_state' => 'type_screen_ready', 'type_screen_state' => 'ready', 'crossmatch_state' => 'pending', 'units_requested' => 2, 'units_allocated' => 0, 'units_issued' => 0, 'type_screen_ready_at' => $a->subMinutes(80)],
                ['readiness_state' => 'crossmatch_ready', 'type_screen_state' => 'ready', 'crossmatch_state' => 'ready', 'units_requested' => 2, 'units_allocated' => 2, 'units_issued' => 0, 'type_screen_ready_at' => $a->subMinutes(150), 'crossmatch_ready_at' => $a->subMinutes(70), 'allocated_at' => $a->subMinutes(70)],
                ['readiness_state' => 'issued', 'type_screen_state' => 'ready', 'crossmatch_state' => 'ready', 'units_requested' => 2, 'units_allocated' => 2, 'units_issued' => 1, 'type_screen_ready_at' => $a->subMinutes(210), 'crossmatch_ready_at' => $a->subMinutes(150), 'allocated_at' => $a->subMinutes(150), 'issued_at' => $a->subMinutes(30)],
                ['readiness_state' => 'testing', 'type_screen_state' => 'pending', 'crossmatch_state' => 'pending', 'units_requested' => 6, 'units_allocated' => 0, 'units_issued' => 0, 'mtp_activated_at' => $a->subMinutes(10)],
            ];
            $explanations = [
                'OR start is blocked until type-and-screen testing begins.',
                'OR start is blocked while type-and-screen compatibility testing is in progress.',
                'OR start is blocked until requested units are crossmatch ready.',
                null, null,
                'The active massive-transfusion response is blocked on continuous blood-product allocation.',
            ];
            foreach ($states as $index => $state) {
                $order = $orders[$index];
                $case = $cases[$index];
                BloodBankReadiness::query()->create([
                    'readiness_uuid' => Uuid::uuid5(Uuid::NAMESPACE_URL, 'zephyrus|ancillary-demo|bb|'.$a->toDateString().'|'.($index + 1))->toString(),
                    'ancillary_order_id' => $order->ancillary_order_id, 'source_id' => $order->source_id,
                    'source_request_key' => 'demo-bb-'.$a->toDateString().'-'.str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT),
                    'case_id' => $case->case_id, 'encounter_id' => $order->encounter_id,
                    'product_class' => $index === 5 ? 'mixed' : 'red_cells',
                    ...$state,
                    'ordered_at' => $order->ordered_at,
                    'needed_by' => $case->scheduled_start_time ?? $a->addMinutes(120 + ($index * 30)),
                    'expires_at' => $index >= 2 && $index <= 4 ? $a->addDays(3) : null,
                    'mtp_closed_at' => null, 'cancelled_at' => null,
                    'demo_owner' => $owner,
                    'metadata' => [
                        'schedule_source' => 'prod.or_cases.scheduled_start_time',
                        'decision_context' => $explanations[$index] === null ? null : [
                            'decision_class' => 'or_gate', 'blocked_object_type' => 'or_case',
                            'blocked_object_id' => (int) $case->case_id, 'explanation' => $explanations[$index],
                        ],
                    ],
                ]);
            }

            return [...$result,
                'readinessRequests' => BloodBankReadiness::query()->where('demo_owner', $owner)->count(),
                'pendingGates' => BloodBankReadiness::query()->where('demo_owner', $owner)
                    ->whereIn('readiness_state', ['ordered', 'testing', 'type_screen_ready', 'unavailable'])->count(),
                'activeMtp' => BloodBankReadiness::query()->where('demo_owner', $owner)->mtpActive()->count(),
            ];
        }, 3);
    }

    /** @return list<object> */
    private function cases(DemoClock $clock): array
    {
        $operatingDate = DB::table('prod.or_cases')->where('is_deleted', false)
            ->whereDate('surgery_date', '<=', $clock->anchor()->toDateString())->max('surgery_date')
            ?? DB::table('prod.or_cases')->where('is_deleted', false)->min('surgery_date');
        $cases = DB::table('prod.or_cases')->where('is_deleted', false)
            ->whereDate('surgery_date', $operatingDate)
            ->orderBy('scheduled_start_time')->orderBy('case_id')->limit(6)->get(['case_id', 'scheduled_start_time'])->all();
        if ($cases === []) {
            throw new RuntimeException('Blood Bank demo requires at least one current OR case.');
        }
        while (count($cases) < 6) {
            $cases[] = $cases[count($cases) % count($cases)];
        }

        return $cases;
    }

    /** @return array<string, mixed> */
    private function meta(object $case, string $cohort, ?string $explanation = null): array
    {
        return array_filter(['or_case_id' => (int) $case->case_id, 'cohort' => $cohort, 'decision_explanation' => $explanation], fn ($value) => $value !== null);
    }
}
