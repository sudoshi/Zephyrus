<?php

namespace App\Models\Radiology;

use App\Casts\JsonObject;
use App\Models\Integration\Source;
use App\Models\Location;
use App\Models\Org\Facility;
use App\Models\Unit;
use Database\Factories\Radiology\ScannerFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Scanner extends Model
{
    /** @use HasFactory<ScannerFactory> */
    use HasFactory;

    protected $table = 'prod.rad_scanners';

    protected $primaryKey = 'rad_scanner_id';

    protected $guarded = [];

    protected $casts = ['capacity' => 'integer', 'portable_capable' => 'boolean', 'metadata' => JsonObject::class];

    protected static function newFactory(): ScannerFactory
    {
        return ScannerFactory::new();
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class, 'source_id', 'source_id');
    }

    public function facility(): BelongsTo
    {
        return $this->belongsTo(Facility::class, 'facility_id', 'facility_id');
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'unit_id', 'unit_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'location_id', 'location_id');
    }

    public function modality(): BelongsTo
    {
        return $this->belongsTo(Modality::class, 'modality_code', 'code');
    }

    public function downtimes(): HasMany
    {
        return $this->hasMany(ScannerDowntime::class, 'rad_scanner_id', 'rad_scanner_id');
    }

    public function exams(): HasMany
    {
        return $this->hasMany(Exam::class, 'rad_scanner_id', 'rad_scanner_id');
    }

    public function scopeOperational(Builder $query): Builder
    {
        return $query->where($this->qualifyColumn('status'), 'operational');
    }
}
