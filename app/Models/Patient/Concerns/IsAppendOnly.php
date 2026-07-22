<?php

namespace App\Models\Patient\Concerns;

use LogicException;

trait IsAppendOnly
{
    public static function bootIsAppendOnly(): void
    {
        static::updating(fn (): never => throw new LogicException(static::class.' is append-only.'));
        static::deleting(fn (): never => throw new LogicException(static::class.' is append-only.'));
    }
}
