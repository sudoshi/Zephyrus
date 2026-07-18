<?php

namespace App\Http\Controllers\Api\Home;

use App\Http\Controllers\Controller;
use App\Models\Home\HomeReferral;
use App\Services\Home\HomeReferralService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Referral funnel + eligibility worklists (ACUM-PRD-HAH-001 §4.2). Funnel
 * transitions are human actions; declines always carry a coded reason
 * (selection-bias analytics, §11).
 */
class HomeReferralController extends Controller
{
    public function __construct(private readonly HomeReferralService $referrals) {}

    public function index(): JsonResponse
    {
        return response()->json($this->referrals->build());
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'patient_ref' => ['required', 'string', 'max:190'],
            'source' => ['required', Rule::in(['ed_diversion', 'inpatient_stepdown', 'direct', 'ambulatory'])],
            'encounter_id' => ['nullable', 'integer'],
            'program_code' => ['nullable', 'string', 'max:60'],
            'payer_class' => ['nullable', 'string', 'max:40'],
            'service_zone' => ['nullable', 'string', 'max:60'],
            'screening' => ['nullable', 'array'],
        ]);

        $validated['referred_by'] = (string) ($request->user()?->email ?? 'unknown');

        $referral = $this->referrals->create($validated);

        return response()->json(['referral' => ['referralUuid' => $referral->referral_uuid, 'status' => $referral->status]], 201);
    }

    public function advance(string $referralUuid): JsonResponse
    {
        $referral = $this->find($referralUuid);
        $advanced = $this->referrals->advance($referral);

        return response()->json(['referral' => [
            'referralUuid' => $advanced->referral_uuid,
            'status' => $advanced->status,
            'activatedAt' => $advanced->activated_at?->toIso8601String(),
        ]]);
    }

    public function decline(Request $request, string $referralUuid): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:60'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        $referral = $this->find($referralUuid);
        $declined = $this->referrals->decline($referral, $validated['reason'], $validated['note'] ?? null);

        return response()->json(['referral' => [
            'referralUuid' => $declined->referral_uuid,
            'status' => $declined->status,
            'declineReason' => $declined->decline_reason,
        ]]);
    }

    private function find(string $referralUuid): HomeReferral
    {
        return HomeReferral::query()
            ->where('referral_uuid', $referralUuid)
            ->where('is_deleted', false)
            ->firstOrFail();
    }
}
