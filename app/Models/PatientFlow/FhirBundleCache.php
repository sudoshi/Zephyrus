<?php

namespace App\Models\PatientFlow;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FhirBundleCache extends Model
{
    protected $table = 'flow_core.fhir_bundle_cache';

    protected $primaryKey = 'fhir_bundle_cache_id';

    protected $guarded = [];

    protected $casts = [
        'generated_at' => 'datetime',
        'bundle_json' => 'array',
    ];

    public function event(): BelongsTo
    {
        return $this->belongsTo(FlowEvent::class, 'flow_event_id', 'flow_event_id');
    }
}
