<?php

namespace App\Services\PatientFlow;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

class Hl7V2Message
{
    /**
     * @param  list<list<string>>  $segments
     * @param  list<string>  $validationErrors
     */
    public function __construct(
        public readonly string $raw,
        public readonly array $segments,
        public readonly string $fieldSeparator = '|',
        public readonly string $componentSeparator = '^',
        public readonly string $repetitionSeparator = '~',
        public readonly string $escapeCharacter = '\\',
        public readonly string $subcomponentSeparator = '&',
        private readonly array $validationErrors = [],
    ) {}

    public static function parse(string $raw): self
    {
        $cleaned = trim($raw, "\x0b\x1c\r\n ");
        $lines = preg_split('/\r\n|\n|\r/', $cleaned) ?: [];
        $msh = $lines[0] ?? '';
        $fieldSeparator = str_starts_with($msh, 'MSH') && strlen($msh) > 3 ? $msh[3] : '|';
        $encoding = str_starts_with($msh, 'MSH'.$fieldSeparator)
            ? explode($fieldSeparator, $msh)[1] ?? '^~\\&'
            : '^~\\&';
        $componentSeparator = $encoding[0] ?? '^';
        $repetitionSeparator = $encoding[1] ?? '~';
        $escapeCharacter = $encoding[2] ?? '\\';
        $subcomponentSeparator = $encoding[3] ?? '&';
        $segments = [];
        $errors = [];

        if (! str_starts_with($msh, 'MSH'.$fieldSeparator)) {
            $errors[] = 'missing_or_invalid_msh';
        }
        if (strlen($encoding) < 4) {
            $errors[] = 'invalid_encoding_characters';
        }

        foreach ($lines as $index => $line) {
            if ($line === '') {
                continue;
            }

            $segment = explode($fieldSeparator, $line);
            if (! preg_match('/^[A-Z0-9]{3}$/', $segment[0] ?? '')) {
                $errors[] = 'invalid_segment_at_'.($index + 1);
            }
            $segments[] = $segment;
        }

        return new self(
            raw: $raw,
            segments: $segments,
            fieldSeparator: $fieldSeparator,
            componentSeparator: $componentSeparator,
            repetitionSeparator: $repetitionSeparator,
            escapeCharacter: $escapeCharacter,
            subcomponentSeparator: $subcomponentSeparator,
            validationErrors: array_values(array_unique($errors)),
        );
    }

    /** @return list<string>|null */
    public function first(string $segment): ?array
    {
        foreach ($this->segments as $item) {
            if (($item[0] ?? null) === $segment) {
                return $item;
            }
        }

        return null;
    }

    /** @return list<list<string>> */
    public function all(string $segment): array
    {
        return array_values(array_filter(
            $this->segments,
            fn (array $item): bool => ($item[0] ?? null) === $segment,
        ));
    }

    public function field(string $segment, int $fieldNumber, ?int $component = null): string
    {
        return $this->fieldAt($segment, 1, $fieldNumber, $component);
    }

    public function fieldAt(
        string $segment,
        int $occurrence,
        int $fieldNumber,
        ?int $component = null,
        int $repetition = 1,
        ?int $subcomponent = null,
        bool $decodeEscapes = true,
    ): string {
        if ($occurrence < 1 || $fieldNumber < 1 || $repetition < 1 || ($component !== null && $component < 1) || ($subcomponent !== null && $subcomponent < 1)) {
            return '';
        }

        $segments = $this->all($segment);
        $selected = $segments[$occurrence - 1] ?? null;
        if ($selected === null) {
            return '';
        }

        if ($segment === 'MSH') {
            $value = $fieldNumber === 1
                ? $this->fieldSeparator
                : ($selected[$fieldNumber - 1] ?? '');
        } else {
            $value = $selected[$fieldNumber] ?? '';
        }

        $value = explode($this->repetitionSeparator, $value)[$repetition - 1] ?? '';
        if ($component !== null) {
            $value = explode($this->componentSeparator, $value)[$component - 1] ?? '';
        }
        if ($subcomponent !== null) {
            $value = explode($this->subcomponentSeparator, $value)[$subcomponent - 1] ?? '';
        }

        return $decodeEscapes ? $this->decode($value) : $value;
    }

    /** @return list<string> */
    public function repetitions(string $segment, int $fieldNumber, int $occurrence = 1): array
    {
        $segments = $this->all($segment);
        $selected = $segments[$occurrence - 1] ?? null;
        if ($selected === null) {
            return [];
        }

        $value = $segment === 'MSH'
            ? ($fieldNumber === 1 ? $this->fieldSeparator : ($selected[$fieldNumber - 1] ?? ''))
            : ($selected[$fieldNumber] ?? '');

        return array_map(fn (string $item): string => $this->decode($item), explode($this->repetitionSeparator, $value));
    }

    /**
     * Returns each anchor segment with its following segments up to the next
     * anchor. This preserves OBR/OBX/NTE grouping without assuming one OBX set.
     *
     * @return list<list<list<string>>>
     */
    public function groups(string $anchorSegment = 'OBR'): array
    {
        $groups = [];
        $current = null;

        foreach ($this->segments as $segment) {
            if (($segment[0] ?? null) === $anchorSegment) {
                if ($current !== null) {
                    $groups[] = $current;
                }
                $current = [$segment];

                continue;
            }

            if ($current !== null) {
                $current[] = $segment;
            }
        }

        if ($current !== null) {
            $groups[] = $current;
        }

        return $groups;
    }

    public function timestamp(string $segment, int $fieldNumber, int $occurrence = 1): ?CarbonImmutable
    {
        $value = $this->fieldAt($segment, $occurrence, $fieldNumber);
        if ($value === '') {
            return null;
        }

        if (! preg_match('/^(\d{4})(\d{2})?(\d{2})?(\d{2})?(\d{2})?(\d{2})?(?:\.(\d{1,6}))?([+-]\d{4})?$/', $value, $parts)) {
            return null;
        }

        $normalized = ($parts[1] ?? '0000')
            .($parts[2] ?? '01')
            .($parts[3] ?? '01')
            .($parts[4] ?? '00')
            .($parts[5] ?? '00')
            .($parts[6] ?? '00');
        $fraction = isset($parts[7]) && $parts[7] !== '' ? '.'.str_pad($parts[7], 6, '0') : '.000000';
        $offset = isset($parts[8]) && $parts[8] !== '' ? $parts[8] : '+0000';
        $parsed = CarbonImmutable::createFromFormat('!YmdHis.uO', $normalized.$fraction.$offset);

        return $parsed === false ? null : $parsed;
    }

    public function messageType(): string
    {
        return $this->field('MSH', 9, 1);
    }

    public function triggerEvent(): string
    {
        return $this->field('MSH', 9, 2);
    }

    public function isValid(): bool
    {
        return $this->validationErrors === [];
    }

    /** @return list<string> */
    public function validationErrors(): array
    {
        return $this->validationErrors;
    }

    public function assertValid(): void
    {
        if (! $this->isValid()) {
            throw new InvalidArgumentException('Invalid HL7 v2 message structure: '.implode(', ', $this->validationErrors).'.');
        }
    }

    public function decode(string $value): string
    {
        $escape = preg_quote($this->escapeCharacter, '/');
        $tokens = [
            'F' => $this->fieldSeparator,
            'S' => $this->componentSeparator,
            'R' => $this->repetitionSeparator,
            'T' => $this->subcomponentSeparator,
            'E' => $this->escapeCharacter,
            '.br' => "\n",
        ];

        $decoded = preg_replace_callback(
            "/{$escape}([^{$escape}]*){$escape}/",
            function (array $match) use ($tokens): string {
                $token = $match[1];
                if (array_key_exists($token, $tokens)) {
                    return $tokens[$token];
                }
                if (str_starts_with($token, 'X') && ctype_xdigit(substr($token, 1)) && strlen(substr($token, 1)) % 2 === 0) {
                    return hex2bin(substr($token, 1)) ?: '';
                }

                return $match[0];
            },
            $value,
        );

        return $decoded ?? $value;
    }
}
