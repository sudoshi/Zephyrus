<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Concerns\RendersMobileEnvelope;
use App\Http\Controllers\Controller;
use App\Services\CommandCenterDataService;
use Illuminate\Http\JsonResponse;

/**
 * Executive "House Brief" (P9) — GET /api/mobile/v1/command/house.
 * Reshapes CommandCenterDataService::build() to the quiet executive glance: the house strain
 * index (0–4, with drivers) + a slim set of hero KPIs. PHI-free, defensible numbers.
 */
class CommandController extends Controller
{
    use RendersMobileEnvelope;

    public function __construct(private readonly CommandCenterDataService $cc) {}

    public function house(): JsonResponse
    {
        $d = $this->cc->build();

        $hero = collect($d['heroMetrics'])->map(fn (array $m) => [
            'key' => $m['key'],
            'label' => $m['label'],
            'display' => $m['display'],
            'value' => $m['value'],
            'status' => $m['status'],
            'target_display' => $m['targetDisplay'] ?? null,
            'trajectory' => ! empty($m['trajectory']) ? [
                'direction' => $m['trajectory']['direction'],
                'good_when_down' => $m['trajectory']['goodWhenDown'],
            ] : null,
        ])->values();

        return $this->envelope([
            'strain' => $d['strain'],
            'hero' => $hero,
            'generated_at' => $d['generatedAtIso'] ?? now()->toIso8601String(),
        ], links: ['web' => url('/dashboard')]);
    }
}
