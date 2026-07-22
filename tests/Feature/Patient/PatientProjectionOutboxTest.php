<?php

namespace Tests\Feature\Patient;

use App\Models\Patient\PatientEncounterProjection;
use App\Models\Patient\PatientNotificationOutbox;
use App\Services\Patient\Projection\SyntheticPatientProjectionProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PatientProjectionOutboxTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_released_projection_appends_one_content_free_publication_outbox_fact(): void
    {
        $fixture = app(SyntheticPatientProjectionProvisioner::class)->provision('projection-outbox');

        $outbox = PatientNotificationOutbox::query()
            ->where('destination', 'projection')
            ->where('event_type', 'patient.projection.released')
            ->orderBy('notification_outbox_id')
            ->get();

        $this->assertCount(6, $outbox);
        $this->assertSame(
            collect($fixture['projections'])->map(fn (PatientEncounterProjection $projection): string => (string) $projection->projection_uuid)->sort()->values()->all(),
            $outbox->pluck('aggregate_uuid')->sort()->values()->all(),
        );
        $this->assertTrue($outbox->every(function (PatientNotificationOutbox $item): bool {
            $metadata = $item->routing_metadata;

            return $item->aggregate_type === 'patient_encounter_projection'
                && $item->encrypted_payload === null
                && $item->payload_digest === null
                && $metadata['schema_version'] === 1
                && $metadata['content_included'] === false
                && in_array($metadata['projection_kind'], [
                    'today',
                    'pathway',
                    'pathway_events',
                    'discharge_readiness',
                    'rounds_summary',
                    'care_team',
                ], true)
                && count($metadata) === 3;
        }));

        app(SyntheticPatientProjectionProvisioner::class)->provision('projection-outbox');
        $this->assertSame(6, PatientNotificationOutbox::query()
            ->where('destination', 'projection')
            ->where('event_type', 'patient.projection.released')
            ->count());
    }

    public function test_draft_projection_never_creates_a_publication_outbox_fact(): void
    {
        $fixture = app(SyntheticPatientProjectionProvisioner::class)->provision('projection-outbox-draft');

        PatientEncounterProjection::query()->create([
            'projection_uuid' => (string) Str::uuid7(),
            'access_grant_id' => $fixture['grant']->getKey(),
            'release_policy_version_id' => $fixture['policy']->getKey(),
            'projection_kind' => 'today',
            'projection_sequence' => 2,
            'content' => [
                'headline' => 'Your plan for today',
                'summary' => 'This draft has not been released to the patient.',
            ],
            'content_schema_version' => 'patient-today.v1',
            'source_version' => 'test-draft-v1',
            'provenance' => [
                'projection_method' => 'automated_test',
                'source_class' => 'synthetic_clinical_record',
                'input_classes' => ['synthetic_schedule'],
                'review_state' => 'automated_test_only',
                'producer_version' => 'test-v1',
            ],
            'source_observed_at' => now()->subMinute(),
            'generated_at' => now(),
            'freshness_class' => 'current',
            'uncertainty' => [
                'level' => 'low',
                'explanation' => 'Plans can change as your care needs change.',
                'can_change' => true,
                'reviewed_at' => now()->toISOString(),
            ],
            'required_scope' => 'today:read',
            'permitted_relationships' => ['self'],
            'release_state' => 'draft',
        ]);

        $this->assertSame(6, PatientNotificationOutbox::query()
            ->where('destination', 'projection')
            ->where('event_type', 'patient.projection.released')
            ->count());
    }
}
