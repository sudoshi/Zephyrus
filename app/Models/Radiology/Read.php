<?php

namespace App\Models\Radiology;

use App\Casts\JsonObject;
use App\Models\Integration\Source;
use Database\Factories\Radiology\ReadFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Read extends Model
{
    /** @use HasFactory<ReadFactory> */
    use HasFactory;

    protected $table = 'prod.rad_reads';

    protected $primaryKey = 'rad_read_id';

    protected $guarded = [];

    protected $casts = [
        'is_teleradiology' => 'boolean', 'preliminary_at' => 'immutable_datetime',
        'final_at' => 'immutable_datetime', 'corrected_at' => 'immutable_datetime', 'metadata' => JsonObject::class,
    ];

    protected static function newFactory(): ReadFactory
    {
        return ReadFactory::new();
    }

    public function exam(): BelongsTo
    {
        return $this->belongsTo(Exam::class, 'rad_exam_id', 'rad_exam_id');
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class, 'source_id', 'source_id');
    }

    public function subspecialty(): BelongsTo
    {
        return $this->belongsTo(Subspecialty::class, 'subspecialty_code', 'code');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_rad_read_id', 'rad_read_id');
    }

    public function addenda(): HasMany
    {
        return $this->hasMany(self::class, 'parent_rad_read_id', 'rad_read_id');
    }

    public function criticalResults(): HasMany
    {
        return $this->hasMany(CriticalResult::class, 'rad_read_id', 'rad_read_id');
    }
}
