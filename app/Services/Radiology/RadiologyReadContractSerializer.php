<?php

namespace App\Services\Radiology;

use App\Models\Radiology\Read;

final class RadiologyReadContractSerializer
{
    /** @return array<string, mixed> */
    public function serialize(Read $read): array
    {
        return [
            'readUuid' => (string) $read->read_uuid,
            'examUuid' => (string) $read->exam->exam_uuid,
            'status' => (string) $read->status,
            'sourceReportVersion' => $read->source_report_version,
            'isTeleradiology' => (bool) $read->is_teleradiology,
            'preliminaryAt' => $read->preliminary_at?->toAtomString(),
            'finalAt' => $read->final_at?->toAtomString(),
            'correctedAt' => $read->corrected_at?->toAtomString(),
            'parentReadUuid' => $read->parent?->read_uuid,
        ];
    }
}
