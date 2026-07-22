<?php

namespace Tests\Unit\Mobile;

use App\Services\Mobile\NotificationUrgencyRegistry;
use App\Services\Mobile\PersonaRelayPolicy;
use Tests\TestCase;

class NotificationUrgencyRegistryTest extends TestCase
{
    private function registry(): NotificationUrgencyRegistry
    {
        // config/hummingbird-notifications.php is auto-loaded by the framework.
        return $this->app->make(NotificationUrgencyRegistry::class);
    }

    public function test_registry_is_internally_consistent_and_phi_free(): void
    {
        $this->assertSame([], $this->registry()->validationErrors());
    }

    public function test_tiers_are_exactly_t1_through_t4_with_monotonic_interruption(): void
    {
        $registry = $this->registry();

        $this->assertSame(['T1', 'T2', 'T3', 'T4'], array_keys($registry->tiers()));

        $levels = array_map(
            fn (string $code): string => (string) $registry->tier($code)['ios_interruption_level'],
            ['T1', 'T2', 'T3', 'T4'],
        );
        $this->assertSame(['critical', 'time-sensitive', 'active', 'passive'], $levels);

        // Only the two most urgent tiers require acknowledgement and are quiet-hours exempt.
        $this->assertTrue($registry->tier('T1')['requires_ack']);
        $this->assertTrue($registry->tier('T2')['requires_ack']);
        $this->assertFalse($registry->tier('T3')['requires_ack']);
        $this->assertFalse($registry->tier('T4')['requires_ack']);
        $this->assertTrue($registry->tier('T1')['quiet_hours_exempt']);
        $this->assertFalse($registry->tier('T4')['quiet_hours_exempt']);
    }

    public function test_events_resolve_to_tiers_and_default_covers_the_unmapped(): void
    {
        $registry = $this->registry();

        // Explicit high-urgency mappings.
        $this->assertSame('T1', $registry->forEvent('alert.escalated')['tier']);
        $this->assertSame('T2', $registry->forEvent('ancillary.sla_breached')['tier']);

        // An unmapped event falls back to the default tier.
        $this->assertSame($registry->defaultTier(), $registry->forEvent('unmapped.event')['tier']);

        // Every mapped event belongs to the recognized relay taxonomy.
        $relay = new PersonaRelayPolicy;
        foreach (array_keys((array) config('hummingbird-notifications.events')) as $eventType) {
            $this->assertTrue(
                $relay->isRecognizedEventType((string) $eventType)
                    || $relay->isDocumentedAsNotEmittedYet((string) $eventType),
                "{$eventType} must be a recognized relay event type.",
            );
        }
    }
}
