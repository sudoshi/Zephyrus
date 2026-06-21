<?php

namespace App\Services;

use App\Models\Barrier;
use InvalidArgumentException;

class BarrierService
{
    public function open(array $data): Barrier
    {
        if (! in_array($data['category'], Barrier::CATEGORIES, true)) {
            throw new InvalidArgumentException("Invalid barrier category: {$data['category']}");
        }

        return Barrier::create([
            'encounter_id' => $data['encounter_id'] ?? null,
            'unit_id' => $data['unit_id'] ?? null,
            'category' => $data['category'],
            'reason_code' => $data['reason_code'] ?? null,
            'description' => $data['description'] ?? null,
            'owner' => $data['owner'] ?? null,
            'status' => 'open',
            'opened_at' => now(),
        ]);
    }

    public function resolve(int $barrierId): Barrier
    {
        $barrier = Barrier::findOrFail($barrierId);
        $barrier->update(['status' => 'resolved', 'resolved_at' => now()]);

        return $barrier;
    }
}
