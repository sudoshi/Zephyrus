<?php

namespace App\Models\Org;

use App\Models\Reference\ServiceLine;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A first-class interfacility transfer edge (internal hub-and-spoke or external
 * partner). Projected into ops.nodes / ops.edges as `transfers_to` edges in Phase 4.
 */
class TransferRelationship extends Model
{
    protected $table = 'hosp_org.transfer_relationships';

    protected $primaryKey = 'transfer_relationship_id';

    protected $fillable = [
        'source_facility_id',
        'source_facility_key',
        'destination_facility_id',
        'destination_facility_key',
        'destination_external_name',
        'service_line_code',
        'program_code',
        'transfer_reason',
        'transport_mode',
        'typical_minutes',
        'typical_miles',
        'direction',
        'acceptance_constraints',
        'escalation_contact',
        'is_external_partner',
        'review_status',
        'source_evidence',
        'is_active',
        'metadata',
    ];

    protected $casts = [
        'source_facility_id' => 'integer',
        'destination_facility_id' => 'integer',
        'typical_minutes' => 'integer',
        'typical_miles' => 'decimal:2',
        'is_external_partner' => 'boolean',
        'is_active' => 'boolean',
        'source_evidence' => 'array',
        'metadata' => 'array',
    ];

    public function sourceFacility(): BelongsTo
    {
        return $this->belongsTo(Facility::class, 'source_facility_id', 'facility_id');
    }

    public function destinationFacility(): BelongsTo
    {
        return $this->belongsTo(Facility::class, 'destination_facility_id', 'facility_id');
    }

    public function serviceLine(): BelongsTo
    {
        return $this->belongsTo(ServiceLine::class, 'service_line_code', 'service_line_code');
    }
}
