<?php

namespace App\Integrations\Healthcare\Services;

use App\Integrations\Healthcare\Contracts\ProjectionHandler;
use App\Integrations\Healthcare\DTO\CanonicalOperationalEvent;
use InvalidArgumentException;

class ProjectionDispatcher implements ProjectionHandler
{
    /** @var list<ProjectionHandler> */
    private array $handlers;

    /** @param iterable<ProjectionHandler> $handlers */
    public function __construct(iterable $handlers)
    {
        $this->handlers = array_values(is_array($handlers) ? $handlers : iterator_to_array($handlers));

        $keys = array_map(fn (ProjectionHandler $handler): string => $handler->key(), $this->handlers);
        if (count($keys) !== count(array_unique($keys))) {
            throw new InvalidArgumentException('Projection handler keys must be unique.');
        }

        $owners = [];
        foreach ($this->handlers as $handler) {
            foreach ($handler->eventTypes() as $eventType) {
                if (isset($owners[$eventType])) {
                    throw new InvalidArgumentException("Canonical event type [{$eventType}] is claimed by multiple projection handlers.");
                }
                $owners[$eventType] = $handler->key();
            }
        }
    }

    public function key(): string
    {
        return 'healthcare.projection_dispatcher';
    }

    public function eventTypes(): array
    {
        $types = [];
        foreach ($this->handlers as $handler) {
            foreach ($handler->eventTypes() as $eventType) {
                $types[$eventType] = true;
            }
        }

        $types = array_keys($types);
        sort($types);

        return $types;
    }

    public function supports(CanonicalOperationalEvent $event): bool
    {
        return $this->handlerFor($event) !== null;
    }

    public function project(CanonicalOperationalEvent $event): void
    {
        $handler = $this->handlerFor($event);
        if ($handler === null) {
            throw new InvalidArgumentException("Unsupported canonical projection event type [{$event->eventType}].");
        }

        $handler->project($event);
    }

    public function projectorKeyFor(CanonicalOperationalEvent $event): ?string
    {
        return $this->handlerFor($event)?->key();
    }

    private function handlerFor(CanonicalOperationalEvent $event): ?ProjectionHandler
    {
        foreach ($this->handlers as $handler) {
            if ($handler->supports($event)) {
                return $handler;
            }
        }

        return null;
    }
}
