<?php

namespace App\Models\Org;

use App\Casts\PgTextArray;
use App\Models\Reference\ServiceLine;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Layer 3: one row per facility x service line x capability_level, with evidence
 * provenance and transfer targets. Absence is modeled here as capability_level
 * none/screen/stabilize, never as a missing row.
 */
class FacilityServiceCapability extends Model
{
    protected $table = 'hosp_org.facility_service_capabilities';

    protected $primaryKey = 'facility_service_capability_id';

    protected $fillable = [
        'facility_id',
        'facility_key',
        'service_line_code',
        'capability_level',
        'programs_present',
        'departments_present',
        'coverage_model',
        'hours',
        'telehealth_support',
        'transfer_out_targets',
        'transfer_in_sources',
        'source_evidence_url',
        'source_evidence_type',
        'review_status',
        'notes',
        'effective_start',
        'effective_end',
        'metadata',
    ];

    protected $casts = [
        'facility_id' => 'integer',
        'programs_present' => PgTextArray::class,
        'departments_present' => PgTextArray::class,
        'transfer_out_targets' => PgTextArray::class,
        'transfer_in_sources' => PgTextArray::class,
        'telehealth_support' => 'boolean',
        'effective_start' => 'date',
        'effective_end' => 'date',
        'metadata' => 'array',
    ];

    public function facility(): BelongsTo
    {
        return $this->belongsTo(Facility::class, 'facility_id', 'facility_id');
    }

    public function serviceLine(): BelongsTo
    {
        return $this->belongsTo(ServiceLine::class, 'service_line_code', 'service_line_code');
    }
}
