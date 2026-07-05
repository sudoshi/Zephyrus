<?php

namespace App\Models\Reference;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A clinically distinct capability within a service line (Level I trauma,
 * comprehensive stroke, kidney transplant, ...). Projected into
 * hosp_ref.programs from config/hospital/programs.php.
 */
class Program extends Model
{
    protected $table = 'hosp_ref.programs';

    protected $primaryKey = 'program_code';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'program_code',
        'service_line_code',
        'display_name',
        'designation_type',
        'designation_body',
        'capability_level_implied',
        'adult_or_pediatric',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];

    public function serviceLine(): BelongsTo
    {
        return $this->belongsTo(ServiceLine::class, 'service_line_code', 'service_line_code');
    }
}
