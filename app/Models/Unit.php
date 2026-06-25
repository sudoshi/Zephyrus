<?php

namespace App\Models;

use App\Models\Facility\FacilitySpace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Unit extends Model
{
    protected $table = 'prod.units';

    protected $primaryKey = 'unit_id';

    protected $fillable = [
        'name', 'abbreviation', 'type', 'staffed_bed_count',
        'ratio_floor', 'access_standard_minutes', 'facility_space_id',
        'created_by', 'modified_by', 'is_deleted',
    ];

    protected $casts = [
        'staffed_bed_count' => 'integer',
        'ratio_floor' => 'integer',
        'access_standard_minutes' => 'integer',
        'is_deleted' => 'boolean',
    ];

    public function beds(): HasMany
    {
        return $this->hasMany(Bed::class, 'unit_id', 'unit_id')->where('is_deleted', false);
    }

    public function facilitySpace(): BelongsTo
    {
        return $this->belongsTo(FacilitySpace::class, 'facility_space_id', 'facility_space_id');
    }

    public function encounters(): HasMany
    {
        return $this->hasMany(Encounter::class, 'unit_id', 'unit_id');
    }
}
