<?php

namespace App\Models\PatientFlow;

use App\Models\Facility\FacilitySpace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AmbientSignalEvent extends Model
{
    protected $table = 'flow_realtime.ambient_signal_events';

    protected $primaryKey = 'ambient_signal_event_id';

    protected $guarded = [];

    protected $casts = [
        'occurred_at' => 'datetime',
        'confidence_score' => 'decimal:4',
        'normalized_payload' => 'array',
        'raw_payload' => 'array',
    ];

    public function adapter(): BelongsTo
    {
        return $this->belongsTo(AmbientSignalAdapterDefinition::class, 'ambient_signal_adapter_id', 'ambient_signal_adapter_id');
    }

    public function facilitySpace(): BelongsTo
    {
        return $this->belongsTo(FacilitySpace::class, 'facility_space_id', 'facility_space_id');
    }

    public function linkedFlowEvent(): BelongsTo
    {
        return $this->belongsTo(FlowEvent::class, 'linked_flow_event_id', 'flow_event_id');
    }
}
