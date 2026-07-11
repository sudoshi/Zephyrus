<?php

return [
    'handoff_required_types' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('TRANSPORT_HANDOFF_REQUIRED_TYPES', 'inpatient,transfer,ems,care_transition')),
    ))),

    'mobile_transporter_capabilities' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('TRANSPORT_MOBILE_CAPABILITIES', 'ambulatory,wheelchair,stretcher,bed,courier')),
    ))),

    'pagination' => [
        'default_per_page' => (int) env('TRANSPORT_DEFAULT_PER_PAGE', 25),
        'max_per_page' => (int) env('TRANSPORT_MAX_PER_PAGE', 100),
    ],

    'resources' => [
        [
            'key' => 'porter_pool',
            'name' => env('TRANSPORT_INTERNAL_TEAM_NAME', 'Summit Patient Transport'),
            'type' => 'team',
            'capacity' => (int) env('TRANSPORT_INTERNAL_TEAM_CAPACITY', 26),
            'capabilities' => ['ambulatory', 'wheelchair', 'stretcher', 'bed', 'courier'],
        ],
        [
            'key' => 'critical_care_team',
            'name' => env('TRANSPORT_CRITICAL_CARE_TEAM_NAME', 'Critical Care Transport'),
            'type' => 'team',
            'capacity' => (int) env('TRANSPORT_CRITICAL_CARE_TEAM_CAPACITY', 4),
            'capabilities' => ['als', 'critical_care', 'ems'],
        ],
        [
            'key' => 'ride_health',
            'name' => 'Ride Health',
            'type' => 'vendor',
            'capacity' => (int) env('TRANSPORT_RIDE_HEALTH_CAPACITY', 12),
            'capabilities' => ['nemt', 'wheelchair', 'stretcher'],
        ],
        [
            'key' => 'uber_health',
            'name' => 'Uber Health',
            'type' => 'vendor',
            'capacity' => (int) env('TRANSPORT_UBER_HEALTH_CAPACITY', 12),
            'capabilities' => ['rideshare'],
        ],
        [
            'key' => 'lyft_healthcare',
            'name' => 'Lyft Healthcare',
            'type' => 'vendor',
            'capacity' => (int) env('TRANSPORT_LYFT_HEALTHCARE_CAPACITY', 12),
            'capabilities' => ['rideshare'],
        ],
        [
            'key' => 'contracted_ambulance',
            'name' => 'Contracted Ambulance',
            'type' => 'vendor',
            'capacity' => (int) env('TRANSPORT_CONTRACTED_AMBULANCE_CAPACITY', 4),
            'capabilities' => ['bls', 'als', 'critical_care', 'ems'],
        ],
    ],
];
