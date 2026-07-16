<?php

namespace Tests\Unit\Integrations;

use App\Integrations\Healthcare\Contracts\ProjectionHandler;
use App\Integrations\Healthcare\DTO\CanonicalOperationalEvent;
use App\Integrations\Healthcare\Services\ProjectionDispatcher;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class ProjectionDispatcherTest extends TestCase
{
    public function test_routes_supported_events_and_exposes_real_projector_key(): void
    {
        $alpha = new RecordingProjectionHandler('alpha', ['Event.Alpha']);
        $beta = new RecordingProjectionHandler('beta', ['Event.Beta']);
        $dispatcher = new ProjectionDispatcher([$alpha, $beta]);
        $event = $this->event('Event.Beta');

        $this->assertTrue($dispatcher->supports($event));
        $this->assertSame('beta', $dispatcher->projectorKeyFor($event));
        $this->assertSame(['Event.Alpha', 'Event.Beta'], $dispatcher->eventTypes());

        $dispatcher->project($event);
        $this->assertSame([], $alpha->projected);
        $this->assertSame(['Event.Beta'], $beta->projected);
    }

    public function test_unsupported_event_is_rejected_without_payload_content(): void
    {
        $dispatcher = new ProjectionDispatcher([new RecordingProjectionHandler('alpha', ['Event.Alpha'])]);
        $event = $this->event('Event.Unsupported', ['secret' => 'never-log-me']);

        try {
            $dispatcher->project($event);
            $this->fail('Expected unsupported event rejection.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('Event.Unsupported', $exception->getMessage());
            $this->assertStringNotContainsString('never-log-me', $exception->getMessage());
        }
    }

    public function test_duplicate_projector_keys_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ProjectionDispatcher([
            new RecordingProjectionHandler('duplicate', ['Event.Alpha']),
            new RecordingProjectionHandler('duplicate', ['Event.Beta']),
        ]);
    }

    public function test_duplicate_event_ownership_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ProjectionDispatcher([
            new RecordingProjectionHandler('alpha', ['Event.Shared']),
            new RecordingProjectionHandler('beta', ['Event.Shared']),
        ]);
    }

    /** @param array<string, mixed> $payload */
    private function event(string $eventType, array $payload = []): CanonicalOperationalEvent
    {
        return new CanonicalOperationalEvent(
            eventId: 'event-id',
            eventType: $eventType,
            entityType: 'test',
            entityRef: 'test-ref',
            payload: $payload,
            occurredAt: CarbonImmutable::parse('2026-07-11T12:00:00-04:00'),
            idempotencyKey: 'test-idempotency',
        );
    }
}

class RecordingProjectionHandler implements ProjectionHandler
{
    /** @var list<string> */
    public array $projected = [];

    /** @param list<string> $eventTypes */
    public function __construct(
        private readonly string $handlerKey,
        private readonly array $eventTypes,
    ) {}

    public function key(): string
    {
        return $this->handlerKey;
    }

    public function eventTypes(): array
    {
        return $this->eventTypes;
    }

    public function supports(CanonicalOperationalEvent $event): bool
    {
        return in_array($event->eventType, $this->eventTypes, true);
    }

    public function project(CanonicalOperationalEvent $event): void
    {
        $this->projected[] = $event->eventType;
    }
}
