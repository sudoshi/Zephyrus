<?php

namespace App\Services\Patient\Projection;

use DateTimeImmutable;
use Exception;
use InvalidArgumentException;

/**
 * Enforces the release boundary before patient projection content is stored.
 *
 * This deliberately accepts a small patient-language contract rather than
 * attempting to redact arbitrary EHR/FHIR/staff payloads after the fact.
 */
class PatientProjectionContentGuard
{
    /** @var array<string, list<string>> */
    private const TOP_LEVEL_KEYS = [
        'today' => [
            'headline', 'summary', 'schedule', 'next_steps', 'care_location',
            'discharge_outlook', 'questions', 'notices',
        ],
        'pathway' => [
            'headline', 'summary', 'current_stage', 'stages', 'milestones',
            'goals', 'education', 'questions', 'notices',
        ],
        'pathway_events' => [
            'headline', 'summary', 'events', 'notices',
        ],
        'discharge_readiness' => [
            'headline', 'summary', 'estimated_range', 'estimated_confidence',
            'criteria', 'unresolved_needs', 'medications', 'follow_up',
            'warning_signs', 'contacts', 'questions', 'notices',
        ],
        'rounds_summary' => [
            'headline', 'summary', 'round_window', 'topics', 'next_steps',
            'questions', 'notices',
        ],
        'care_team' => [
            'headline', 'summary', 'members',
            'communication_options', 'questions', 'notices',
        ],
    ];

    /** @var list<string> */
    private const FORBIDDEN_KEYS = [
        'mrn', 'patient_id', 'patient_ref', 'subject_id', 'source_id',
        'source_ref', 'source_encounter_id', 'source_encounter_ref',
        'encounter_id', 'staff_id', 'user_id', 'provider_id', 'employee_id',
        'resource_id', 'fhir_id', 'raw_fhir', 'raw_payload', 'identifier',
        'npi', 'email', 'phone', 'phone_number', 'token', 'access_token',
        'other_patient_data', 'medical_record_number', 'internal_database_identifier',
        'staff_internal_note', 'staff_note', 'staff_disagreement', 'private_comment',
        'bed_management_priority', 'queue_rank', 'capacity', 'staffing',
        'worker_location', 'private_schedule', 'risk_score', 'priority_score',
        'internal_priority', 'incident', 'unreleased_result', 'unreleased_document',
        'result', 'document', 'diagnosis', 'prognosis', 'causality', 'exact_time',
        'eta', 'personal_staff_contact', 'message_routing', 'safety_review_metadata',
        'advertising_id', 'tracking_id', 'isolation_detail', 'barrier_code',
        'dispatch_rank', 'pharmacy_queue', 'administration_comment',
    ];

    /** @var list<string> */
    private const PROVENANCE_KEYS = [
        'projection_method', 'source_class', 'input_classes', 'review_state',
        'producer_version', 'trace_digest',
    ];

    /** @var list<string> */
    private const UNCERTAINTY_KEYS = [
        'level', 'explanation', 'can_change', 'reviewed_at',
    ];

    /** @var list<string> */
    private const RELATIONSHIPS = [
        'self', 'legal_representative', 'guardian', 'caregiver', 'proxy', 'other',
    ];

    public function __construct(private readonly PatientProjectionStateVocabulary $stateVocabulary) {}

    /**
     * @param  array<string, mixed>  $content
     * @param  array<string, mixed>  $provenance
     * @param  array<string, mixed>  $uncertainty
     * @param  list<string>  $relationships
     */
    public function assertSafe(
        string $projectionKind,
        array $content,
        array $provenance,
        array $uncertainty,
        array $relationships,
    ): void {
        $allowedTopLevel = self::TOP_LEVEL_KEYS[$projectionKind] ?? null;

        if ($allowedTopLevel === null) {
            throw new InvalidArgumentException('unsupported_patient_projection_kind');
        }

        $unexpected = array_diff(array_keys($content), $allowedTopLevel);
        if ($unexpected !== []) {
            throw new InvalidArgumentException('patient_projection_content_key_not_allowed');
        }
        if (array_diff(['headline', 'summary'], array_keys($content)) !== []) {
            throw new InvalidArgumentException('patient_projection_content_required');
        }

        $this->assertContentSchema($projectionKind, $content);

        $this->assertAllowedObjectKeys($provenance, self::PROVENANCE_KEYS, 'patient_projection_provenance_key_not_allowed');
        $this->assertAllowedObjectKeys($uncertainty, self::UNCERTAINTY_KEYS, 'patient_projection_uncertainty_key_not_allowed');
        if (array_diff(
            ['projection_method', 'source_class', 'input_classes', 'review_state', 'producer_version'],
            array_keys($provenance),
        ) !== []) {
            throw new InvalidArgumentException('patient_projection_provenance_required');
        }
        if (array_diff(['level', 'explanation', 'can_change', 'reviewed_at'], array_keys($uncertainty)) !== []) {
            throw new InvalidArgumentException('patient_projection_uncertainty_required');
        }

        foreach (['projection_method', 'source_class', 'review_state', 'producer_version'] as $field) {
            $this->assertString($provenance[$field]);
        }
        $this->assertStringList($provenance['input_classes']);
        if (array_key_exists('trace_digest', $provenance)
            && (! is_string($provenance['trace_digest']) || preg_match('/^[0-9a-f]{64,128}$/', $provenance['trace_digest']) !== 1)) {
            throw new InvalidArgumentException('patient_projection_provenance_trace_invalid');
        }

        $this->assertEnum($uncertainty['level'], ['low', 'medium', 'high', 'unknown']);
        $this->assertString($uncertainty['explanation']);
        if (! is_bool($uncertainty['can_change'])) {
            throw new InvalidArgumentException('patient_projection_uncertainty_invalid');
        }
        $this->assertIsoTimestamp($uncertainty['reviewed_at']);

        if ($relationships === [] || array_diff($relationships, self::RELATIONSHIPS) !== []) {
            throw new InvalidArgumentException('patient_projection_relationship_not_allowed');
        }

        $this->walk($content);
        $this->walkValues($provenance);
        $this->walkValues($uncertainty);
    }

    /** @param  array<string, mixed>  $content */
    public function digest(string $projectionKind, string $schemaVersion, array $content): string
    {
        return hash('sha256', implode('|', [
            $projectionKind,
            $schemaVersion,
            json_encode($this->canonicalize($content), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ]));
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  list<string>  $allowed
     */
    private function assertAllowedObjectKeys(array $values, array $allowed, string $error): void
    {
        if (array_diff(array_keys($values), $allowed) !== []) {
            throw new InvalidArgumentException($error);
        }
    }

    private function walk(mixed $value): void
    {
        if (is_array($value)) {
            foreach ($value as $key => $child) {
                if (is_string($key)) {
                    $normalized = strtolower($key);
                    if (in_array($normalized, self::FORBIDDEN_KEYS, true)
                        || str_starts_with($normalized, 'raw_')
                        || str_starts_with($normalized, 'fhir_')
                        || str_starts_with($normalized, 'source_')
                        || str_ends_with($normalized, '_source_id')) {
                        throw new InvalidArgumentException('patient_projection_source_field_forbidden');
                    }
                }

                $this->walk($child);
            }

            return;
        }

        if (is_string($value) && preg_match(
            '/(?:Patient|Encounter|Practitioner|RelatedPerson|Organization|Location)\/[A-Za-z0-9.-]+|urn:oid:|\bMRN\s*[:=]/i',
            $value,
        ) === 1) {
            throw new InvalidArgumentException('patient_projection_raw_source_value_forbidden');
        }
    }

    /** @param  array<string, mixed>  $content */
    private function assertContentSchema(string $kind, array $content): void
    {
        foreach (['headline', 'summary'] as $field) {
            if (array_key_exists($field, $content)) {
                $this->assertString($content[$field]);
            }
        }

        foreach (['questions', 'notices'] as $field) {
            if (array_key_exists($field, $content)) {
                $this->assertStringList($content[$field]);
            }
        }

        match ($kind) {
            'today' => $this->assertToday($content),
            'pathway' => $this->assertPathway($content),
            'pathway_events' => $this->assertPathwayEvents($content),
            'discharge_readiness' => $this->assertDischargeReadiness($content),
            'rounds_summary' => $this->assertRoundsSummary($content),
            'care_team' => $this->assertCareTeam($content),
            default => throw new InvalidArgumentException('unsupported_patient_projection_kind'),
        };
    }

    /** @param  array<string, mixed>  $content */
    private function assertDischargeReadiness(array $content): void
    {
        if (array_key_exists('estimated_range', $content)) {
            $this->assertString($content['estimated_range']);
        }
        if (array_key_exists('estimated_confidence', $content)) {
            $this->assertValueType($content['estimated_confidence'], 'timing_confidence');
        }
        if (array_key_exists('criteria', $content)) {
            $this->assertObjectList($content['criteria'], [
                'item_uuid' => 'uuid',
                'label' => 'string',
                'status' => 'discharge_criteria_status',
                'detail' => 'string',
            ], ['item_uuid', 'label', 'status']);
        }
        foreach (['unresolved_needs', 'warning_signs'] as $field) {
            if (array_key_exists($field, $content)) {
                $this->assertStringList($content[$field]);
            }
        }
        if (array_key_exists('medications', $content)) {
            $this->assertObjectList($content['medications'], [
                'item_uuid' => 'uuid',
                'name' => 'string',
                'purpose' => 'string',
            ], ['item_uuid', 'name']);
        }
        if (array_key_exists('follow_up', $content)) {
            $this->assertObjectList($content['follow_up'], [
                'item_uuid' => 'uuid',
                'label' => 'string',
                'when' => 'string',
            ], ['item_uuid', 'label', 'when']);
        }
        if (array_key_exists('contacts', $content)) {
            $this->assertObjectList($content['contacts'], [
                'item_uuid' => 'uuid',
                'label' => 'string',
                'route' => 'contact_route',
            ], ['item_uuid', 'label', 'route']);
        }
    }

    /** @param  array<string, mixed>  $content */
    private function assertPathwayEvents(array $content): void
    {
        if (array_key_exists('events', $content)) {
            $this->assertObjectList($content['events'], [
                'event_uuid' => 'uuid',
                'title' => 'string',
                'when' => 'string',
                'detail' => 'string',
                'category' => 'pathway_event_category',
                'status' => 'pathway_event_status',
            ], ['event_uuid', 'title', 'when', 'status']);
        }
    }

    /** @param  array<string, mixed>  $content */
    private function assertRoundsSummary(array $content): void
    {
        if (array_key_exists('round_window', $content)) {
            $this->assertString($content['round_window']);
        }
        if (array_key_exists('topics', $content)) {
            $this->assertObjectList($content['topics'], [
                'topic_uuid' => 'uuid',
                'title' => 'string',
                'summary' => 'string',
                'status' => 'rounds_topic_status',
            ], ['topic_uuid', 'title', 'summary', 'status']);
        }
        if (array_key_exists('next_steps', $content)) {
            $this->assertStringList($content['next_steps']);
        }
    }

    /** @param  array<string, mixed>  $content */
    private function assertToday(array $content): void
    {
        if (array_key_exists('schedule', $content)) {
            $this->assertObjectList($content['schedule'], [
                'item_uuid' => 'uuid',
                'label' => 'string',
                'detail' => 'string',
                'status' => 'schedule_status',
                'time_window' => 'string',
                'timing_confidence' => 'timing_confidence',
                'preparation' => 'string',
                'can_change' => 'bool',
            ], ['item_uuid', 'label', 'status', 'time_window', 'can_change']);
        }

        foreach (['next_steps'] as $field) {
            if (array_key_exists($field, $content)) {
                $this->assertStringList($content[$field]);
            }
        }

        if (array_key_exists('care_location', $content)) {
            $this->assertObject($content['care_location'], [
                'facility_display_name' => 'string',
                'unit_display_name' => 'string',
                'room_display_name' => 'string',
                'status' => 'location_status',
            ], ['status']);
        }

        if (array_key_exists('discharge_outlook', $content)) {
            $this->assertObject($content['discharge_outlook'], [
                'estimated_range' => 'string',
                'confidence' => 'timing_confidence',
                'readiness_topics' => 'string_list',
                'remaining_steps' => 'string_list',
                'can_change' => 'bool',
            ], ['estimated_range', 'confidence', 'can_change']);
        }
    }

    /** @param  array<string, mixed>  $content */
    private function assertPathway(array $content): void
    {
        if (array_key_exists('current_stage', $content)) {
            $this->assertString($content['current_stage']);
        }

        if (array_key_exists('stages', $content)) {
            $this->assertObjectList($content['stages'], [
                'stage_uuid' => 'uuid',
                'title' => 'string',
                'status' => 'stage_status',
                'summary' => 'string',
                'expected_range' => 'string',
                'timing_confidence' => 'timing_confidence',
                'can_change' => 'bool',
            ], ['stage_uuid', 'title', 'status', 'summary', 'can_change']);
        }

        if (array_key_exists('milestones', $content)) {
            $this->assertObjectList($content['milestones'], [
                'milestone_uuid' => 'uuid',
                'title' => 'string',
                'status' => 'milestone_status',
                'detail' => 'string',
                'timing' => 'string',
                'timing_confidence' => 'timing_confidence',
                'can_change' => 'bool',
            ], ['milestone_uuid', 'title', 'status']);
        }

        if (array_key_exists('goals', $content)) {
            $this->assertObjectList($content['goals'], [
                'goal_uuid' => 'uuid',
                'author_type' => 'goal_author',
                'label' => 'string',
                'explanation' => 'string',
                'status' => 'goal_status',
                'target_range' => 'string',
            ], ['goal_uuid', 'author_type', 'label', 'status']);
        }

        if (array_key_exists('education', $content)) {
            $this->assertObjectList($content['education'], [
                'item_uuid' => 'uuid',
                'title' => 'string',
                'summary' => 'string',
            ], ['item_uuid', 'title', 'summary']);
        }
    }

    /** @param  array<string, mixed>  $content */
    private function assertCareTeam(array $content): void
    {
        if (array_key_exists('members', $content)) {
            $this->assertObjectList($content['members'], [
                'member_uuid' => 'uuid',
                'display_name' => 'string',
                'role' => 'string',
                'service' => 'string',
                'responsibilities' => 'string_list',
                'contact_route' => 'contact_route',
            ], ['member_uuid', 'display_name', 'role', 'responsibilities', 'contact_route']);
        }

        if (array_key_exists('communication_options', $content)) {
            if (! is_array($content['communication_options']) || ! array_is_list($content['communication_options'])) {
                throw new InvalidArgumentException('patient_projection_content_type_invalid');
            }

            foreach ($content['communication_options'] as $option) {
                $this->assertEnum($option, ['speak_with_bedside_staff', 'call_button_for_urgent_help']);
            }
        }
    }

    /**
     * @param  array<string, string>  $schema
     * @param  list<string>  $required
     */
    private function assertObjectList(mixed $value, array $schema, array $required = []): void
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw new InvalidArgumentException('patient_projection_content_type_invalid');
        }

        foreach ($value as $item) {
            $this->assertObject($item, $schema, $required);
        }
    }

    /**
     * @param  array<string, string>  $schema
     * @param  list<string>  $required
     */
    private function assertObject(mixed $value, array $schema, array $required = []): void
    {
        if (! is_array($value) || array_is_list($value)) {
            throw new InvalidArgumentException('patient_projection_content_type_invalid');
        }

        if (array_diff(array_keys($value), array_keys($schema)) !== []) {
            throw new InvalidArgumentException('patient_projection_nested_content_key_not_allowed');
        }
        if (array_diff($required, array_keys($value)) !== []) {
            throw new InvalidArgumentException('patient_projection_nested_content_required');
        }

        foreach ($value as $field => $fieldValue) {
            $this->assertValueType($fieldValue, $schema[$field]);
        }
    }

    private function assertValueType(mixed $value, string $type): void
    {
        match ($type) {
            'string' => $this->assertString($value),
            'uuid' => $this->assertUuid($value),
            'bool' => is_bool($value) ?: throw new InvalidArgumentException('patient_projection_content_type_invalid'),
            'string_list' => $this->assertStringList($value),
            'schedule_status',
            'stage_status',
            'milestone_status',
            'pathway_event_status',
            'pathway_event_category',
            'rounds_topic_status',
            'discharge_criteria_status',
            'goal_status',
            'goal_author',
            'timing_confidence',
            'location_status',
            'contact_route' => $this->stateVocabulary->assertCode($type, $value),
            default => throw new InvalidArgumentException('patient_projection_content_schema_invalid'),
        };
    }

    private function assertString(mixed $value): void
    {
        if (! is_string($value) || trim($value) === '' || mb_strlen($value) > 2000) {
            throw new InvalidArgumentException('patient_projection_content_type_invalid');
        }
    }

    private function assertUuid(mixed $value): void
    {
        if (! is_string($value) || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $value) !== 1) {
            throw new InvalidArgumentException('patient_projection_content_type_invalid');
        }
    }

    private function assertIsoTimestamp(mixed $value): void
    {
        if (! is_string($value)
            || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/', $value) !== 1) {
            throw new InvalidArgumentException('patient_projection_timestamp_invalid');
        }

        try {
            new DateTimeImmutable($value);
        } catch (Exception) {
            throw new InvalidArgumentException('patient_projection_timestamp_invalid');
        }
    }

    private function assertStringList(mixed $value): void
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw new InvalidArgumentException('patient_projection_content_type_invalid');
        }

        foreach ($value as $item) {
            $this->assertString($item);
        }
    }

    /** @param  list<string>  $allowed */
    private function assertEnum(mixed $value, array $allowed): void
    {
        if (! is_string($value) || ! in_array($value, $allowed, true)) {
            throw new InvalidArgumentException('patient_projection_content_value_invalid');
        }
    }

    /** @param  array<string, mixed>  $values */
    private function walkValues(array $values): void
    {
        foreach ($values as $value) {
            if (is_array($value)) {
                $this->walkValues($value);
            } elseif (is_string($value) && preg_match(
                '/(?:Patient|Encounter|Practitioner|RelatedPerson|Organization|Location)\/[A-Za-z0-9.-]+|urn:oid:|\bMRN\s*[:=]/i',
                $value,
            ) === 1) {
                throw new InvalidArgumentException('patient_projection_raw_source_value_forbidden');
            }
        }
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        foreach ($value as $key => $child) {
            $value[$key] = $this->canonicalize($child);
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }
}
