<?php

namespace App\Models\Facility;

use App\Casts\PgTextArray;
use App\Models\Bed;
use App\Models\Location;
use App\Models\Room;
use App\Models\Unit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

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
        'location_role',
        'program_code',
        'capability_tags',
        'facility_key',
    ];

    protected $casts = [
        'floor_number' => 'integer',
        'geometry' => 'array',
        'attributes' => 'array',
        'source_confidence' => 'decimal:4',
        'capability_tags' => PgTextArray::class,
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

    /**
     * Layer 4 many-to-many: every service line this physical space can serve.
     */
    public function serviceLines(): HasMany
    {
        return $this->hasMany(FacilitySpaceServiceLine::class, 'facility_space_id', 'facility_space_id');
    }

    /**
     * The single primary service line for this space (uq_fssl_one_primary guarantees at most one).
     */
    public function primaryServiceLine(): HasOne
    {
        return $this->hasOne(FacilitySpaceServiceLine::class, 'facility_space_id', 'facility_space_id')
            ->where('primary_flag', true);
    }
}
