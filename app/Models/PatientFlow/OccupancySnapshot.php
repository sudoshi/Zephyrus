<?php

namespace App\Models\PatientFlow;

use App\Models\Facility\FacilitySpace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OccupancySnapshot extends Model
{
    protected $table = 'flow_core.occupancy_snapshots';

    protected $primaryKey = 'occupancy_snapshot_id';

    protected $guarded = [];

    protected $casts = [
        'snapshot_at' => 'datetime',
        'active_patient_count' => 'integer',
        'service_line_counts' => 'array',
        'acuity_counts' => 'array',
        'occupancy_details' => 'array',
        'timer_status_counts' => 'array',
        'service_line_timer_counts' => 'array',
        'persona_timer_counts' => 'array',
        'active_blocker_counts' => 'array',
        'projection_window' => 'array',
    ];

    public function facilitySpace(): BelongsTo
    {
        return $this->belongsTo(FacilitySpace::class, 'facility_space_id', 'facility_space_id');
    }

    public function generatedFromEvent(): BelongsTo
    {
        return $this->belongsTo(FlowEvent::class, 'generated_from_event_id', 'flow_event_id');
    }
}
