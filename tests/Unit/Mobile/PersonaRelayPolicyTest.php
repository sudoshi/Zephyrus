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
}
