<?php

namespace App\Data\Ancillary;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class AncillaryPageFilters
{
    /**
     * @param  list<string>  $departments
     * @param  list<string>  $priorities
     * @param  list<int>  $unitIds
     * @param  list<string>  $states
     */
    public function __construct(
        public array $departments = [],
        public array $priorities = [],
        public array $unitIds = [],
        public array $states = [],
        public ?DateTimeImmutable $from = null,
        public ?DateTimeImmutable $to = null,
        public ?string $search = null,
        public int $page = 1,
        public int $perPage = 50,
        public ?string $cursor = null,
    ) {
        if ($page < 1 || $perPage < 1 || $perPage > 200) {
            throw new InvalidArgumentException('Page must be positive and perPage must be between 1 and 200.');
        }

        if ($from !== null && $to !== null && $to < $from) {
            throw new InvalidArgumentException('Filter end time cannot precede start time.');
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'departments' => $this->departments,
            'priorities' => $this->priorities,
            'unitIds' => $this->unitIds,
            'states' => $this->states,
            'from' => $this->from?->format(DATE_ATOM),
            'to' => $this->to?->format(DATE_ATOM),
            'search' => $this->search,
            'page' => $this->page,
            'perPage' => $this->perPage,
            'cursor' => $this->cursor,
        ];
    }
}
