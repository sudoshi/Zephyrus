<?php

/**
 * Capability-tag vocabulary (report §4): structured bed/room/facility-space
 * capabilities. Projected into hosp_ref.capability_tags by `deployment:seed-registry`
 * (App\Services\Deployment\ServiceLineRegistrar). These tag codes are the sanctioned
 * values for prod.beds/prod.rooms.capability_tags and
 * hosp_space.facility_spaces.capability_tags; `deployment:audit-tags` flags orphans.
 *
 * Plan: docs/superpowers/plans/2026-07-04-service-line-location-deployment-implementation.md (§5)
 *
 * Per-tag schema (maps 1:1 to hosp_ref.capability_tags columns):
 *   name        -> display_name
 *   category    -> tag_category   (bed|room|isolation|procedure|monitoring|imaging)
 *   applies_to  -> applies_to     (subset of {bed,room,facility_space})
 *   description -> description
 */

return [

    'capability_tags' => [

        // Monitoring
        'telemetry'             => ['name' => 'Telemetry', 'category' => 'monitoring', 'applies_to' => ['bed', 'room'], 'description' => 'Continuous cardiac / vitals telemetry monitoring.'],
        'neuro_monitoring'      => ['name' => 'Neuro Monitoring', 'category' => 'monitoring', 'applies_to' => ['bed', 'room'], 'description' => 'ICP / EEG / continuous neurologic monitoring.'],

        // Bed capability
        'ventilator'            => ['name' => 'Ventilator', 'category' => 'bed', 'applies_to' => ['bed', 'room'], 'description' => 'Mechanical ventilation supported at the bedside.'],
        'bariatric'             => ['name' => 'Bariatric', 'category' => 'bed', 'applies_to' => ['bed', 'room'], 'description' => 'Bariatric-rated bed / room capacity.'],
        'pediatric'             => ['name' => 'Pediatric', 'category' => 'bed', 'applies_to' => ['bed', 'room'], 'description' => 'Pediatric-configured bed / room.'],
        'neonatal'              => ['name' => 'Neonatal', 'category' => 'bed', 'applies_to' => ['bed', 'room'], 'description' => 'Neonatal isolette / warmer position.'],
        'burn'                  => ['name' => 'Burn', 'category' => 'bed', 'applies_to' => ['bed', 'room'], 'description' => 'Burn-care bed (temperature/humidity controlled).'],

        // Room / environment
        'medical_gas'           => ['name' => 'Medical Gas', 'category' => 'room', 'applies_to' => ['bed', 'room', 'facility_space'], 'description' => 'Piped medical gas / suction / oxygen.'],
        'lift'                  => ['name' => 'Patient Lift', 'category' => 'room', 'applies_to' => ['room'], 'description' => 'Ceiling or mobile patient lift available.'],
        'behavioral_safe'       => ['name' => 'Behavioral Safe', 'category' => 'room', 'applies_to' => ['bed', 'room'], 'description' => 'Ligature-resistant behavioral-health-safe environment.'],
        'ob'                    => ['name' => 'Obstetric', 'category' => 'room', 'applies_to' => ['room'], 'description' => 'Labor / delivery / OB-configured room.'],
        'trauma_resus'          => ['name' => 'Trauma Resuscitation', 'category' => 'room', 'applies_to' => ['room'], 'description' => 'Trauma resuscitation bay.'],
        'transplant'            => ['name' => 'Transplant', 'category' => 'room', 'applies_to' => ['bed', 'room'], 'description' => 'Transplant-designated care room.'],

        // Isolation
        'negative_pressure'     => ['name' => 'Negative Pressure', 'category' => 'isolation', 'applies_to' => ['bed', 'room'], 'description' => 'Airborne-isolation negative-pressure room.'],
        'protective_environment' => ['name' => 'Protective Environment', 'category' => 'isolation', 'applies_to' => ['bed', 'room'], 'description' => 'Positive-pressure protective / immunocompromised isolation.'],

        // Procedure
        'hemodialysis'          => ['name' => 'Hemodialysis', 'category' => 'procedure', 'applies_to' => ['bed', 'room'], 'description' => 'Hemodialysis-capable position (water treatment).'],
        'crrt'                  => ['name' => 'CRRT', 'category' => 'procedure', 'applies_to' => ['bed', 'room'], 'description' => 'Continuous renal replacement therapy at bedside.'],
        'ecmo'                  => ['name' => 'ECMO', 'category' => 'procedure', 'applies_to' => ['bed', 'room'], 'description' => 'Extracorporeal membrane oxygenation supported.'],
        'chemo'                 => ['name' => 'Chemotherapy', 'category' => 'procedure', 'applies_to' => ['bed', 'room'], 'description' => 'Chemotherapy / infusion administration position.'],
        'hybrid_or'             => ['name' => 'Hybrid OR', 'category' => 'procedure', 'applies_to' => ['room', 'facility_space'], 'description' => 'Hybrid operating room with fixed imaging.'],
        'robotic'               => ['name' => 'Robotic Surgery', 'category' => 'procedure', 'applies_to' => ['room', 'facility_space'], 'description' => 'Robotic surgical platform available.'],

        // Imaging
        'fluoro'                => ['name' => 'Fluoroscopy', 'category' => 'imaging', 'applies_to' => ['room', 'facility_space'], 'description' => 'Fluoroscopy-equipped room.'],
        'mri'                   => ['name' => 'MRI', 'category' => 'imaging', 'applies_to' => ['room', 'facility_space'], 'description' => 'MRI modality suite.'],
        'ct'                    => ['name' => 'CT', 'category' => 'imaging', 'applies_to' => ['room', 'facility_space'], 'description' => 'CT modality suite.'],

        // Priority pathways
        'stroke_priority'       => ['name' => 'Stroke Priority', 'category' => 'procedure', 'applies_to' => ['room', 'facility_space'], 'description' => 'Stroke-priority pathway (door-to-needle/thrombectomy).'],
        'stemi_priority'        => ['name' => 'STEMI Priority', 'category' => 'procedure', 'applies_to' => ['room', 'facility_space'], 'description' => 'STEMI-priority pathway (door-to-balloon).'],

    ],
];
