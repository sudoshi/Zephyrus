<?php

/**
 * Governed care-pathway milestone → Arena OCEL activity mapping (FLOW-4D plan
 * Phase D2).
 *
 * The compiled reference model (ReferenceModelCompiler) lists a governed
 * version's milestone stable_keys as its "activities". To evaluate conformance,
 * each must line up with an activity the OCEL log actually emits
 * (arena/app/pathways.py vocabulary). They do not yet: the DRG catalog's
 * day-phased milestone keys were authored for a different purpose than the
 * safety-pathway event stream. That gap is real and clinical — this map is where
 * it gets closed, one reviewed entry at a time, and `care-pathways:ocel-coverage`
 * reports every milestone still unmapped.
 *
 * Nothing here is executed. Phase D ships dark behind the deferred serving flags
 * (plan G-9); this is the honest scaffolding a future clinical mapping review
 * fills in.
 */

return [

    // milestone stable_key => OCEL activity. Intentionally near-empty; every
    // unmapped milestone is surfaced by the coverage command as the gap list.
    'activities' => [
        // 'sepsis_recognition_m01' => 'sepsis_recognition',   // example shape
    ],

    // Mirror of the sidecar reference-model activity vocabulary
    // (arena/app/pathways.py). A mapping target outside this set is a typo the
    // coverage command flags as an unknown target.
    'ocel_vocabulary' => [
        // sepsis (SEP-3)
        'sepsis_recognition', 'vitals_sirs', 'lactate_order', 'lactate_result',
        'blood_culture_order', 'antibiotic_administration', 'fluid_bolus_30mlkg',
        'vasopressor_start', 'repeat_lactate_order', 'repeat_lactate_result',
        // surgical safety (WHO checklist)
        'Safety_Check',
        // home hospital (AHCAH)
        'home-refer', 'home-activate', 'home-visit-complete',
        'home-escalation-open', 'home-escalation-resolve', 'home-discharge',
    ],

];
