<?php

namespace App\Services\Radiology;

use App\Models\Ancillary\AncillaryOrder;
use App\Models\Barrier;
use App\Services\Audit\UserAuditRecorder;
use App\Services\BarrierService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class RadiologyBarrierService
{
    public function __construct(
        private readonly BarrierService $barriers,
        private readonly UserAuditRecorder $audit,
    ) {}

    /** @param array<string, mixed> $data */
    public function open(array $data, Request $request): Barrier
    {
        return DB::transaction(function () use ($data, $request): Barrier {
            $order = AncillaryOrder::query()->where('department', 'rad')->where('order_uuid', $data['orderUuid'])->lockForUpdate()->firstOrFail();
            if ($order->encounter_id === null) {
                throw ValidationException::withMessages(['orderUuid' => 'A Radiology barrier requires an encounter-linked order.']);
            }

            $reason = DB::table('hosp_ref.ancillary_barrier_reasons')
                ->where('department', 'rad')->where('reason_code', $data['reasonCode'])->where('is_active', true)->first();
            if ($reason === null) {
                throw ValidationException::withMessages(['reasonCode' => 'The selected Radiology barrier reason is not active.']);
            }

            $barrier = $this->barriers->open([
                'encounter_id' => $order->encounter_id,
                'unit_id' => $order->unit_id,
                'category' => $reason->category,
                'reason_code' => $reason->reason_code,
                'description' => $data['description'] ?? null,
                'owner' => $data['owner'] ?? null,
            ]);

            DB::table('prod.ancillary_breaches')->where('ancillary_order_id', $order->ancillary_order_id)
                ->where('status', 'open')->whereNull('barrier_id')->update(['barrier_id' => $barrier->barrier_id, 'updated_at' => now()]);

            $this->audit->record('ancillary.barrier.opened', 'activity', 'success', [
                'request' => $request,
                'target_type' => 'ancillary_barrier',
                'target_id' => (string) $barrier->barrier_id,
                'reason' => strtolower((string) $reason->reason_code),
                'http_status' => 201,
                'changes' => ['barrier_status' => ['from' => null, 'to' => 'open']],
            ]);

            return $barrier->refresh();
        }, 3);
    }
}
