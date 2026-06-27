<?php

namespace App\Http\Controllers;

use App\Models\RtdcRedStretchPlan;
use App\Models\Unit;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class RTDCController extends Controller
{
    /**
     * Upsert the Red Stretch Plan (capacity-escalation note) for a unit.
     * Persisted to prod.rtdc_red_stretch_plans — one current plan per unit.
     */
    public function updateRedStretchPlan(Request $request)
    {
        $validated = $request->validate([
            'unitId' => ['required', 'integer', Rule::exists(Unit::class, 'unit_id')],
            'plan' => 'required|string|max:500',
        ]);

        $plan = RtdcRedStretchPlan::updateOrCreate(
            ['unit_id' => $validated['unitId']],
            [
                'plan' => $validated['plan'],
                'updated_by' => $request->user()?->name,
            ],
        );

        return response()->json([
            'message' => 'Red Stretch Plan updated successfully',
            'plan' => [
                'unitId' => $plan->unit_id,
                'text' => $plan->plan,
                'updatedBy' => $plan->updated_by,
                'updatedAt' => $plan->updated_at?->toIso8601String(),
            ],
        ]);
    }
}
