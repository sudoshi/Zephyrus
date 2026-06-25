<?php

namespace App\Services\PatientFlow;

class Hl7LocationData
{
    public function __construct(
        public readonly string $pointOfCare = '',
        public readonly string $room = '',
        public readonly string $bed = '',
        public readonly string $facility = '',
    ) {}

    public static function parse(?string $value): self
    {
        if (! $value) {
            return new self;
        }

        $parts = explode('^', $value);
        while (count($parts) < 4) {
            $parts[] = '';
        }

        return new self(
            (string) $parts[0],
            (string) $parts[1],
            (string) $parts[2],
            (string) $parts[3],
        );
    }

    public function locationCode(): string
    {
        return $this->bed ?: ($this->room ?: ($this->pointOfCare ?: 'UNKNOWN'));
    }

    public function toHl7(): string
    {
        return rtrim(implode('^', [
            $this->pointOfCare,
            $this->room,
            $this->bed,
            $this->facility,
        ]), '^');
    }
}
