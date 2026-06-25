<?php

namespace App\Models\Facility;

use App\Models\Bed;
use App\Models\Location;
use App\Models\Room;
use App\Models\Unit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OperationalSpaceMap extends Model
{
    protected $table = 'hosp_space.operational_space_maps';

    protected $primaryKey = 'operational_space_map_id';

    protected $fillable = [
        'facility_space_id',
        'location_id',
        'room_id',
        'unit_id',
        'bed_id',
        'mapping_type',
        'mapping_confidence',
        'evidence',
        'active',
    ];

    protected $casts = [
        'mapping_confidence' => 'decimal:4',
        'evidence' => 'array',
        'active' => 'boolean',
    ];

    public function facilitySpace(): BelongsTo
    {
        return $this->belongsTo(FacilitySpace::class, 'facility_space_id', 'facility_space_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'location_id', 'location_id');
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class, 'room_id', 'room_id');
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'unit_id', 'unit_id');
    }

    public function bed(): BelongsTo
    {
        return $this->belongsTo(Bed::class, 'bed_id', 'bed_id');
    }
}
