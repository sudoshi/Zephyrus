<?php

namespace App\Models\Facility;

use App\Models\Bed;
use App\Models\Location;
use App\Models\Room;
use App\Models\Unit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FacilitySpace extends Model
{
    protected $table = 'hosp_space.facility_spaces';

    protected $primaryKey = 'facility_space_id';

    protected $fillable = [
        'blueprint_object_id',
        'parent_space_id',
        'space_code',
        'space_name',
        'space_category',
        'floor_label',
        'floor_number',
        'service_line_code',
        'acuity_level',
        'status',
        'geometry',
        'attributes',
        'source_system',
        'source_confidence',
    ];

    protected $casts = [
        'floor_number' => 'integer',
        'geometry' => 'array',
        'attributes' => 'array',
        'source_confidence' => 'decimal:4',
    ];

    public function blueprintObject(): BelongsTo
    {
        return $this->belongsTo(BlueprintObject::class, 'blueprint_object_id', 'blueprint_object_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_space_id', 'facility_space_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_space_id', 'facility_space_id');
    }

    public function operationalMaps(): HasMany
    {
        return $this->hasMany(OperationalSpaceMap::class, 'facility_space_id', 'facility_space_id');
    }

    public function locations(): HasMany
    {
        return $this->hasMany(Location::class, 'facility_space_id', 'facility_space_id');
    }

    public function rooms(): HasMany
    {
        return $this->hasMany(Room::class, 'facility_space_id', 'facility_space_id');
    }

    public function units(): HasMany
    {
        return $this->hasMany(Unit::class, 'facility_space_id', 'facility_space_id');
    }

    public function beds(): HasMany
    {
        return $this->hasMany(Bed::class, 'facility_space_id', 'facility_space_id');
    }
}
