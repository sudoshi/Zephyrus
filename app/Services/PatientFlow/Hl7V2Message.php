<?php

namespace App\Services\PatientFlow;

class Hl7V2Message
{
    /**
     * @param  list<list<string>>  $segments
     */
    public function __construct(
        public readonly string $raw,
        public readonly array $segments,
    ) {}

    public static function parse(string $raw): self
    {
        $cleaned = trim($raw, "\x0b\x1c\r\n ");
        $lines = preg_split('/\r\n|\n|\r/', $cleaned) ?: [];
        $segments = [];

        foreach ($lines as $line) {
            if ($line === '') {
                continue;
            }

            $segments[] = explode('|', $line);
        }

        return new self($raw, $segments);
    }

    /**
     * @return list<string>|null
     */
    public function first(string $segment): ?array
    {
        foreach ($this->segments as $item) {
            if (($item[0] ?? null) === $segment) {
                return $item;
            }
        }

        return null;
    }

    /**
     * @return list<list<string>>
     */
    public function all(string $segment): array
    {
        return array_values(array_filter(
            $this->segments,
            fn (array $item): bool => ($item[0] ?? null) === $segment,
        ));
    }

    public function field(string $segment, int $fieldNumber, ?int $component = null): string
    {
        $seg = $this->first($segment);
        if (! $seg) {
            return '';
        }

        if ($segment === 'MSH') {
            $value = $fieldNumber === 1
                ? '|'
                : ($seg[$fieldNumber - 1] ?? '');
        } else {
            $value = $seg[$fieldNumber] ?? '';
        }

        if ($component !== null) {
            $parts = explode('^', $value);

            return $parts[$component - 1] ?? '';
        }

        return $value;
    }

    public function messageType(): string
    {
        return $this->field('MSH', 9, 1);
    }

    public function triggerEvent(): string
    {
        return $this->field('MSH', 9, 2);
    }
}
