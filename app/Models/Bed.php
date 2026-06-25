<?php

namespace App\Models;

use App\Models\Facility\FacilitySpace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Bed extends Model
{
    protected $table = 'prod.beds';

    protected $primaryKey = 'bed_id';

    protected $fillable = [
        'unit_id', 'label', 'status', 'bed_type',
        'isolation_capable', 'facility_space_id',
        'created_by', 'modified_by', 'is_deleted',
    ];

    protected $casts = [
        'isolation_capable' => 'boolean',
        'is_deleted' => 'boolean',
    ];

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'unit_id', 'unit_id');
    }

    public function facilitySpace(): BelongsTo
    {
        return $this->belongsTo(FacilitySpace::class, 'facility_space_id', 'facility_space_id');
    }

    public function scopeAvailable($query)
    {
        return $query->where('status', 'available')->where('is_deleted', false);
    }
}
