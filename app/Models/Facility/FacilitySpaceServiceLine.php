<?php

namespace App\Models\Facility;

use App\Casts\PgTextArray;
use App\Models\Reference\Program;
use App\Models\Reference\ServiceLine;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Layer 4 bridge row: one physical facility space serving one service line (with an
 * optional program), flagged primary or shared. Exactly one primary_flag=true row
 * per space is enforced by the partial unique index uq_fssl_one_primary.
 *
 * text[] columns use PgTextArray (Postgres array literals, not JSON) — see the same
 * cast on the hosp_org models.
 */
class FacilitySpaceServiceLine extends Model
{
    protected $table = 'hosp_space.facility_space_service_lines';

    protected $primaryKey = 'facility_space_service_line_id';

    protected $fillable = [
        'facility_space_id',
        'service_line_code',
        'program_code',
        'location_role',
        'primary_flag',
        'capability_tags',
        'effective_start',
        'effective_end',
        'evidence',
    ];

    protected $casts = [
        'facility_space_id' => 'integer',
        'primary_flag' => 'boolean',
        'capability_tags' => PgTextArray::class,
        'effective_start' => 'date',
        'effective_end' => 'date',
        'evidence' => 'array',
    ];

    public function facilitySpace(): BelongsTo
    {
        return $this->belongsTo(FacilitySpace::class, 'facility_space_id', 'facility_space_id');
    }

    public function serviceLine(): BelongsTo
    {
        return $this->belongsTo(ServiceLine::class, 'service_line_code', 'service_line_code');
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class, 'program_code', 'program_code');
    }
}
