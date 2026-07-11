<?php

namespace App\Support\Api;

/**
 * Keep JSON map fields encoded as objects even when they are empty.
 */
final class JsonMap
{
    public static function from(?array $value): object
    {
        return (object) ($value ?? []);
    }
}
