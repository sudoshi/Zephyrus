<?php

namespace App\Integrations\Healthcare\Services;

use App\Integrations\Healthcare\Contracts\ProjectionHandler;
use App\Integrations\Healthcare\DTO\CanonicalOperationalEvent;
use App\Rtdc\EventDispatcher;
use App\Rtdc\Events\CanonicalEvent as RtdcCanonicalEvent;
use InvalidArgumentException;

class RtdcProjectionHandler implements ProjectionHandler
{
    public function __construct(private readonly EventDispatcher $dispatcher) {}

    public function key(): string
    {
        return 'rtdc.census';
    }

    public function eventTypes(): array
    {
        return [
            RtdcCanonicalEvent::ENCOUNTER_STARTED,
            RtdcCanonicalEvent::ENCOUNTER_TRANSFERRED,
            RtdcCanonicalEvent::ENCOUNTER_DISCHARGED,
            RtdcCanonicalEvent::BED_STATUS_CHANGED,
            RtdcCanonicalEvent::ACUITY_CHANGED,
        ];
    }

    public function supports(CanonicalOperationalEvent $event): bool
    {
        return in_array($event->eventType, $this->eventTypes(), true);
    }

    public function project(CanonicalOperationalEvent $event): void
    {
        if (! $this->supports($event)) {
            throw new InvalidArgumentException("Unsupported RTDC projection event [{$event->eventType}].");
        }

        $this->dispatcher->dispatch(new RtdcCanonicalEvent(
            eventId: $event->eventId,
            type: $event->eventType,
            encounterRef: $event->entityRef,
            payload: $event->payload,
            occurredAt: $event->occurredAt,
        ));
    }
}
