<?php

namespace App\Models\PatientFlow;

use App\Models\Facility\FacilitySpace;
use App\Models\Integration\CanonicalEventRecord;
use App\Models\Integration\Source;
use App\Models\Raw\InboundMessage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FlowEvent extends Model
{
    protected $table = 'flow_core.flow_events';

    protected $primaryKey = 'flow_event_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = [
        'occurred_at' => 'datetime',
        'recorded_at' => 'datetime',
        'diagnosis_codes' => 'array',
        'order_codes' => 'array',
        'observation_codes' => 'array',
        'medication_codes' => 'array',
        'deidentified' => 'boolean',
        'metadata' => 'array',
    ];

    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class, 'source_id', 'source_id');
    }

    public function inboundMessage(): BelongsTo
    {
        return $this->belongsTo(InboundMessage::class, 'inbound_message_id', 'inbound_message_id');
    }

    public function canonicalEvent(): BelongsTo
    {
        return $this->belongsTo(CanonicalEventRecord::class, 'canonical_event_id', 'canonical_event_id');
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(PatientIdentity::class, 'patient_ref', 'patient_ref');
    }

    public function encounter(): BelongsTo
    {
        return $this->belongsTo(FlowEncounter::class, 'encounter_ref', 'encounter_ref');
    }

    public function fromFacilitySpace(): BelongsTo
    {
        return $this->belongsTo(FacilitySpace::class, 'from_facility_space_id', 'facility_space_id');
    }

    public function toFacilitySpace(): BelongsTo
    {
        return $this->belongsTo(FacilitySpace::class, 'to_facility_space_id', 'facility_space_id');
    }
}
