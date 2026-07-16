<?php

namespace App\Integrations\Healthcare\Services;

use App\Integrations\Healthcare\Contracts\ProjectionHandler;
use App\Integrations\Healthcare\DTO\CanonicalOperationalEvent;
use App\Observability\MetricRecorder;
use App\Rtdc\EventDispatcher;
use App\Rtdc\Events\CanonicalEvent as RtdcCanonicalEvent;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

class RtdcProjectionHandler implements ProjectionHandler
{
    public function __construct(
        private readonly EventDispatcher $dispatcher,
        private readonly MetricRecorder $metrics,
    ) {}

    public function supports(CanonicalOperationalEvent $event): bool
    {
        return in_array($event->eventType, [
            RtdcCanonicalEvent::ENCOUNTER_STARTED,
            RtdcCanonicalEvent::ENCOUNTER_TRANSFERRED,
            RtdcCanonicalEvent::ENCOUNTER_DISCHARGED,
            RtdcCanonicalEvent::BED_STATUS_CHANGED,
            RtdcCanonicalEvent::ACUITY_CHANGED,
        ], true);
    }

    public function project(CanonicalOperationalEvent $event): void
    {
        $startedAt = hrtime(true);
        $attributes = Str::isUuid($event->eventId)
            ? ['zephyrus.event.uuid' => $event->eventId]
            : [];

        try {
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
            $this->metrics->span(
                'zephyrus.integration.rtdc.project',
                'ok',
                $this->durationMs($startedAt),
                [...$attributes, 'zephyrus.outcome' => 'projected'],
            );
        } catch (Throwable $exception) {
            $this->metrics->span(
                'zephyrus.integration.rtdc.project',
                'error',
                $this->durationMs($startedAt),
                [...$attributes, 'error.type' => $exception instanceof InvalidArgumentException
                    ? 'unsupported_projection_event'
                    : 'rtdc_projection_failed'],
            );

            throw $exception;
        }
    }

    private function durationMs(int $startedAt): int
    {
        return max(0, min(86_400_000, (int) ((hrtime(true) - $startedAt) / 1_000_000)));
    }
}
