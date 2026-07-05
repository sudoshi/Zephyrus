<?php

namespace App\Domain\Ocel;

use Carbon\CarbonInterface;

/**
 * The normalized unit the EmissionMap produces and the OcelProjector writes: one
 * OCEL event, the objects it touches (with qualifiers), any object-to-object
 * bindings it establishes, and any time-varying attribute changes it records.
 *
 * Immutable value object — built once per source row, never mutated (global
 * coding-style: create new objects, don't mutate). Object ids on E2O/O2O are the
 * de-identified projection ids (patient-<hash>, enc-<hash>, bed-<code>, …);
 * PHI never reaches this DTO.
 */
final class EmittedEvent
{
    /**
     * @param  string  $id  deterministic event id (idempotent upsert key)
     * @param  array<int, array{id: string, type: string, qualifier: string, attrs?: array<string, mixed>}>  $objects  E2O links + the object rows they imply
     * @param  array<int, array{from: string, to: string, qualifier: string}>  $o2o  qualified object-to-object bindings
     * @param  array<string, mixed>  $attrs  event attributes (operational, PHI-free)
     * @param  array<int, array{object_id: string, attr: string, value: mixed, at: CarbonInterface}>  $changes  time-varying object attribute changes
     */
    public function __construct(
        public readonly string $id,
        public readonly string $activity,
        public readonly CarbonInterface $timestamp,
        public readonly string $sourceSystem,
        public readonly string $sourceRef,
        public readonly array $objects = [],
        public readonly array $o2o = [],
        public readonly array $attrs = [],
        public readonly array $changes = [],
    ) {}
}
