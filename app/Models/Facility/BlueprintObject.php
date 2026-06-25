<?php

namespace App\Models\Facility;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class BlueprintObject extends Model
{
    protected $table = 'hosp_ingest.blueprint_objects';

    protected $primaryKey = 'blueprint_object_id';

    protected $fillable = [
        'blueprint_import_id',
        'parent_blueprint_object_id',
        'source_object_id',
        'source_global_id',
        'object_code',
        'object_name',
        'object_category',
        'source_layer',
        'source_material',
        'floor_label',
        'floor_number',
        'geometry_kind',
        'position_ft',
        'size_ft',
        'bounds_ft',
        'centroid_x_ft',
        'centroid_y_ft',
        'centroid_z_ft',
        'gross_area_sqft',
        'net_area_sqft',
        'metadata',
        'classification',
        'extraction_confidence',
        'review_status',
        'canonical_schema',
        'canonical_table',
        'canonical_id',
    ];

    protected $casts = [
        'floor_number' => 'integer',
        'position_ft' => 'array',
        'size_ft' => 'array',
        'bounds_ft' => 'array',
        'metadata' => 'array',
        'classification' => 'array',
        'centroid_x_ft' => 'decimal:4',
        'centroid_y_ft' => 'decimal:4',
        'centroid_z_ft' => 'decimal:4',
        'gross_area_sqft' => 'decimal:2',
        'net_area_sqft' => 'decimal:2',
        'extraction_confidence' => 'decimal:4',
    ];

    public function import(): BelongsTo
    {
        return $this->belongsTo(BlueprintImport::class, 'blueprint_import_id', 'blueprint_import_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_blueprint_object_id', 'blueprint_object_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_blueprint_object_id', 'blueprint_object_id');
    }

    public function facilitySpace(): HasOne
    {
        return $this->hasOne(FacilitySpace::class, 'blueprint_object_id', 'blueprint_object_id');
    }
}
