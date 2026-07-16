<?php

namespace App\Models\Fhir;

use App\Models\Integration\Source;
use App\Models\Raw\IngestRun;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ResourceVersion extends Model
{
    protected $table = 'fhir.resource_versions';

    protected $primaryKey = 'resource_version_id';

    protected $guarded = [];

    protected $casts = [
        'last_updated' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    protected function resourceData(): Attribute
    {
        return Attribute::make(get: fn (mixed $value, array $attributes): array => app(
            \App\Security\ClinicalPayloads\ClinicalPayloadHydrator::class,
        )->required(
            isset($attributes['payload_object_id']) ? (int) $attributes['payload_object_id'] : null,
            (int) $attributes['source_id'],
            'fhir_resource',
            $value,
        ));
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class, 'source_id', 'source_id');
    }

    public function ingestRun(): BelongsTo
    {
        return $this->belongsTo(IngestRun::class, 'ingest_run_id', 'ingest_run_id');
    }
}
