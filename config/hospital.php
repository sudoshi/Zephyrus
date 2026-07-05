<?php

/**
 * Hospital manifest resolver (Phase 5 — Per-Facility Manifest Generation).
 *
 * Maps a business `facility_key` (hosp_org.facilities.facility_key) to the config
 * file that holds its manifest, so App\Support\Hospital\HospitalManifest can load any
 * deployed facility instead of hardcoding Summit. Summit Regional stays the reference
 * deployment and the default; new facilities are generated with
 * `hospital:generate-manifest {facilityKey} --write=config/hospital/<key>.php` and then
 * registered here.
 *
 * Plan: docs/superpowers/plans/2026-07-04-service-line-location-deployment-implementation.md (Phase 5)
 */

return [
    // The facility loaded when no key is given — every existing HospitalManifest consumer
    // resolves to this, so behaviour is unchanged.
    'default_facility' => env('HOSPITAL_DEFAULT_FACILITY', 'SUMMIT_REGIONAL'),

    // facility_key => path (relative to base_path()) of the manifest file it require()s.
    'manifests' => [
        'SUMMIT_REGIONAL' => 'config/hospital/hospital-1.php',
    ],
];
