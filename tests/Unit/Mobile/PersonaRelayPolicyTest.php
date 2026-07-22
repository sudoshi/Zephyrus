<?php

namespace Tests\Unit\Mobile;

use App\Services\Mobile\PersonaRelayPolicy;
use PHPUnit\Framework\TestCase;

class PersonaRelayPolicyTest extends TestCase
{
    public function test_standardized_event_types_are_relayed_or_marked_not_emitted_yet(): void
    {
        $policy = new PersonaRelayPolicy;

        foreach (PersonaRelayPolicy::STANDARD_EVENT_TYPES as $eventType) {
            $this->assertTrue(
                $policy->isRecognizedEventType($eventType) || $policy->isDocumentedAsNotEmittedYet($eventType),
                "{$eventType} must be handled by relay policy or documented as not emitted yet.",
            );

            if (! $policy->isRecognizedEventType($eventType)) {
                continue;
            }

            $relay = $policy->forEvent($eventType, domain: 'ops', scope: ['actor_role' => 'capacity_lead']);

            $this->assertNotEmpty($relay['affected_roles'], "{$eventType} must identify affected personas.");
            $this->assertArrayHasKey('push_tier', $relay, "{$eventType} must define push tier.");
            $this->assertIsArray($relay['notify_now'], "{$eventType} must define immediate recipients as an array.");
            $this->assertIsArray($relay['activity_only'], "{$eventType} must define activity recipients as an array.");
        }

        $this->assertSame(
            [],
            array_values(array_diff(
                array_keys(PersonaRelayPolicy::NOT_EMITTED_YET_EVENT_TYPES),
                PersonaRelayPolicy::STANDARD_EVENT_TYPES,
            )),
            'Every not-emitted-yet event note must correspond to a standardized event type.',
        );
    }

    public function test_activity_taxonomy_partitions_standard_events_without_implying_completeness(): void
    {
        $policy = new PersonaRelayPolicy;
        $taxonomy = $policy->activityTaxonomy();

        // Emitted and pending partition the full taxonomy: no overlap, no omission.
        $this->assertSame(
            [],
            array_values(array_intersect($taxonomy['emitted'], $taxonomy['pending'])),
            'An event type cannot be both emitted and on the backlog.',
        );
        $this->assertEqualsCanonicalizing(
            PersonaRelayPolicy::STANDARD_EVENT_TYPES,
            array_merge($taxonomy['emitted'], $taxonomy['pending']),
            'Emitted and pending event types must together cover the whole taxonomy.',
        );

        // Completeness must never be implied: the backlog is non-empty today and
        // every pending entry carries a tracked reason.
        $this->assertNotEmpty($taxonomy['emitted']);
        $this->assertNotEmpty($taxonomy['pending']);
        $this->assertSame($taxonomy['pending'], $policy->pendingEventTypes());
        foreach ($policy->pendingBacklog() as $eventType => $reason) {
            $this->assertContains($eventType, $taxonomy['pending']);
            $this->assertNotEmpty(
                trim((string) $reason),
                "{$eventType} backlog entry must explain why it is not emitted yet.",
            );
        }

        // Every event type claimed as emitted has a real relay path.
        foreach ($taxonomy['emitted'] as $eventType) {
            $this->assertFalse(
                $policy->isDocumentedAsNotEmittedYet($eventType),
                "{$eventType} is claimed as emitted but is on the not-emitted backlog.",
            );
            $this->assertTrue(
                $policy->isRecognizedEventType($eventType),
                "{$eventType} is claimed as emitted but has no relay mapping.",
            );
            $this->assertNotEmpty($policy->forEvent($eventType)['affected_roles']);
        }
    }
}
