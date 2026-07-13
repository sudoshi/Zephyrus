<?php

namespace App\Observability;

use App\Security\ClinicalPayloads\ClinicalContentGuard;
use InvalidArgumentException;

/**
 * INT-OBS 4 — the PHI-safe metric/span attribute contract.
 *
 * An OpenTelemetry attribute bag whose every key and value is validated to be
 * PHI-free and secret-free. Keys are constrained to the OTel semantic-convention
 * shape (lowercase dotted identifiers); values are bounded scalars only. Every
 * value additionally passes ClinicalContentGuard so a caller can never smuggle
 * a clinical payload or credential onto a metric/trace attribute.
 *
 * This is a tripwire, not a de-identification service: build attributes from
 * stable codes, counts, and identifiers (request IDs, canonical event UUIDs) —
 * never from raw upstream content.
 */
final class SpanAttributes
{
    private const KEY_PATTERN = '/^[a-z][a-z0-9_]*(\.[a-z0-9_]+)*$/';

    private const MAX_VALUE_LENGTH = 256;

    /** @param array<string, int|float|bool|string> $attributes */
    private function __construct(private readonly array $attributes) {}

    /**
     * @param  array<string, int|float|bool|string|null>  $attributes
     */
    public static function make(array $attributes, ?ClinicalContentGuard $guard = null): self
    {
        $guard ??= app(ClinicalContentGuard::class);
        $safe = [];
        foreach ($attributes as $key => $value) {
            if ($value === null) {
                continue;
            }
            if (! is_string($key) || preg_match(self::KEY_PATTERN, $key) !== 1) {
                throw new InvalidArgumentException('observability_attribute_key_invalid');
            }
            if (! is_scalar($value)) {
                throw new InvalidArgumentException('observability_attribute_value_type_invalid');
            }
            if (is_string($value) && mb_strlen($value) > self::MAX_VALUE_LENGTH) {
                throw new InvalidArgumentException('observability_attribute_value_too_long');
            }
            $safe[$key] = $value;
        }

        // Defense in depth: the whole bag passes the clinical-content tripwire.
        $guard->assertSafe($safe, 'observability_attribute_content_rejected');

        ksort($safe);

        return new self($safe);
    }

    /** @return array<string, int|float|bool|string> */
    public function toArray(): array
    {
        return $this->attributes;
    }

    public function with(string $key, int|float|bool|string|null $value, ?ClinicalContentGuard $guard = null): self
    {
        return self::make([...$this->attributes, $key => $value], $guard);
    }
}
