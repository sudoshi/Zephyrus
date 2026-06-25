<?php

namespace App\Models;

use App\Models\Facility\FacilitySpace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Location extends Model
{
    protected $table = 'prod.locations';

    protected $primaryKey = 'location_id';

    protected $fillable = [
        'name',
        'abbreviation',
        'type',
        'pos_type',
        'active_status',
        'created_by',
        'modified_by',
        'facility_space_id',
        'is_deleted',
    ];

    protected $casts = [
        'active_status' => 'boolean',
        'is_deleted' => 'boolean',
    ];

    public function rooms(): HasMany
    {
        return $this->hasMany(Room::class, 'location_id', 'location_id');
    }

    public function facilitySpace(): BelongsTo
    {
        return $this->belongsTo(FacilitySpace::class, 'facility_space_id', 'facility_space_id');
    }

    public function scopeActive($query)
    {
        return $query->where('active_status', true)
            ->where('is_deleted', false);
    }

    public function scopeOperatingRooms($query)
    {
        return $query->where('type', 'OR');
    }
}
