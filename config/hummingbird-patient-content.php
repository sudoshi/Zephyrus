<?php

/*
|--------------------------------------------------------------------------
| Hummingbird Patient content registry
|--------------------------------------------------------------------------
|
| This registry owns stable state codes and their default patient-language
| rendering. Projection content still requires its separate clinical/privacy
| release workflow; this file is not a mechanism to approve free text or turn
| arbitrary runtime configuration into patient content.
|
| Native clients intentionally preserve these codes and use matching bundled
| copy. New codes require a reviewed registry/version change and matching
| native handling before a projection can be released.
|
*/

return [
    'state_vocabulary' => [
        'version' => 'patient-state-vocabulary.v1-draft',
        'default_locale' => 'en-US',
        'locales' => [
            'en-US' => [
                'schedule_status' => [
                    'requested' => 'Requested',
                    'planned' => 'Planned',
                    'confirmed' => 'Confirmed',
                    'in_progress' => 'Happening now',
                    'completed' => 'Completed',
                    'delayed' => 'Delayed',
                    'canceled' => 'No longer planned',
                ],
                'stage_status' => [
                    'planned' => 'Planned',
                    'current' => 'Happening now',
                    'completed' => 'Completed',
                    'delayed' => 'Delayed',
                    'canceled' => 'No longer planned',
                ],
                'milestone_status' => [
                    'planned' => 'Planned',
                    'current' => 'Happening now',
                    'completed' => 'Completed',
                    'delayed' => 'Delayed',
                    'canceled' => 'No longer planned',
                ],
                'pathway_event_status' => [
                    'planned' => 'Planned',
                    'current' => 'Happening now',
                    'completed' => 'Completed',
                    'delayed' => 'Delayed',
                    'canceled' => 'No longer planned',
                ],
                'pathway_event_category' => [
                    'test' => 'Test',
                    'procedure' => 'Procedure',
                    'transport' => 'Transportation',
                    'other' => 'Care update',
                ],
                'rounds_topic_status' => [
                    'discussed' => 'Discussed',
                    'current' => 'Being reviewed',
                    'planned' => 'Planned',
                ],
                'discharge_criteria_status' => [
                    'met' => 'Met',
                    'pending' => 'Still needed',
                    'at_risk' => 'Needs attention',
                ],
                'goal_status' => [
                    'proposed' => 'Being considered',
                    'planned' => 'Planned',
                    'in_progress' => 'In progress',
                    'completed' => 'Completed',
                    'paused' => 'Paused',
                    'canceled' => 'No longer planned',
                ],
                'goal_author' => [
                    'patient' => 'Your goal',
                    'representative' => 'Goal shared by your representative',
                    'care_team' => 'Care-team goal',
                ],
                'timing_confidence' => [
                    'confirmed' => 'Confirmed',
                    'estimated' => 'Estimated',
                    'unknown' => 'Not yet known',
                ],
                'location_status' => [
                    'current' => 'Current location',
                    'updating' => 'Location being updated',
                    'unknown' => 'Location not yet known',
                ],
                'contact_route' => [
                    'speak_with_bedside_staff' => 'Speak with bedside staff',
                    'call_button_for_urgent_help' => 'Use your bedside call button for urgent help',
                ],
            ],
        ],
    ],
];
