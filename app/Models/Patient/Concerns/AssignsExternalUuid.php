<?php

namespace App\Models\Patient\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

trait AssignsExternalUuid
{
    public static function bootAssignsExternalUuid(): void
    {
        static::creating(function (Model $model): void {
            $column = $model->externalUuidColumn();

            if (blank($model->getAttribute($column))) {
                $model->setAttribute($column, (string) Str::uuid());
            }
        });
    }

    public function externalUuidColumn(): string
    {
        return defined('static::EXTERNAL_UUID_COLUMN')
            ? static::EXTERNAL_UUID_COLUMN
            : 'uuid';
    }
}
