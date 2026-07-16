<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use JsonException;
use stdClass;

/**
 * Keeps object-shaped JSONB columns object-shaped when an empty PHP array is
 * assigned. Laravel's stock array cast encodes [] as a JSON array, which would
 * violate the ancillary schema's explicit jsonb_typeof(...)=object contract.
 *
 * @implements CastsAttributes<array<string, mixed>, array<string, mixed>|stdClass>
 */
class JsonObject implements CastsAttributes
{
    /** @return array<string, mixed> */
    public function get(Model $model, string $key, mixed $value, array $attributes): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        try {
            $decoded = json_decode((string) $value, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException("{$key} does not contain valid JSON.", previous: $exception);
        }

        if (! is_array($decoded) || array_is_list($decoded) && $decoded !== []) {
            throw new InvalidArgumentException("{$key} must contain a JSON object.");
        }

        return $decoded;
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): string
    {
        if ($value instanceof stdClass) {
            $value = (array) $value;
        }

        if (! is_array($value) || array_is_list($value) && $value !== []) {
            throw new InvalidArgumentException("{$key} must be an associative array or empty object.");
        }

        try {
            return json_encode($value === [] ? new stdClass : $value, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException("{$key} could not be encoded as JSON.", previous: $exception);
        }
    }
}
