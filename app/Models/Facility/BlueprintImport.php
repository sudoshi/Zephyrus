<?php

namespace App\Models\Facility;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BlueprintImport extends Model
{
    protected $table = 'hosp_ingest.blueprint_imports';

    protected $primaryKey = 'blueprint_import_id';

    protected $fillable = [
        'import_uuid',
        'source_name',
        'source_type',
        'source_uri',
        'source_checksum',
        'facility_code',
        'facility_name',
        'coordinate_units',
        'coordinate_system',
        'floor_height_ft',
        'status',
        'metadata',
        'imported_by',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'metadata' => 'array',
        'floor_height_ft' => 'decimal:2',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function objects(): HasMany
    {
        return $this->hasMany(BlueprintObject::class, 'blueprint_import_id', 'blueprint_import_id');
    }
}
