<?php

namespace App\Nightingale\Inpatient;

/**
 * Request-scoped port for a future governed current-inpatient determination.
 *
 * It returns only a state. It does not expose patient_ref, encounter_id,
 * source-system identifiers, or a patient-visible handle.
 */
interface NightingaleInpatientContextSource
{
    public function state(): NightingaleInpatientSourceState;
}
