<?php

namespace App\Services\Ancillary;

use App\Data\Ancillary\FreshnessEnvelope;
use App\Models\Ancillary\AncillarySlaDefinition;

final class AncillaryContractSerializer
{
    /** @return array<string, mixed> */
    public function freshness(FreshnessEnvelope $freshness): array
    {
        return $freshness->toArray();
    }

    /** @return array<string, mixed> */
    public function slaDefinition(AncillarySlaDefinition $definition): array
    {
        return [
            'definitionUuid' => (string) $definition->definition_uuid,
            'department' => (string) $definition->department,
            'metricKey' => (string) $definition->metric_key,
            'label' => (string) $definition->label,
            'startMilestoneCode' => (string) $definition->start_milestone_code,
            'stopMilestoneCode' => (string) $definition->stop_milestone_code,
            'priority' => $definition->priority,
            'patientClass' => $definition->patient_class,
            'statistic' => (string) $definition->statistic,
            'warningMinutes' => $definition->warning_minutes,
            'breachMinutes' => $definition->breach_minutes,
            'targetValue' => $definition->target_value === null ? null : (float) $definition->target_value,
            'direction' => (string) $definition->direction,
            'unit' => (string) $definition->unit,
            'effectiveFrom' => $definition->effective_from?->toAtomString(),
            'effectiveTo' => $definition->effective_to?->toAtomString(),
            'version' => (int) $definition->version,
            'active' => (bool) $definition->active,
            'definitionText' => (string) $definition->definition_text,
            'sourceReferenceId' => $definition->source_reference_id,
        ];
    }
}
