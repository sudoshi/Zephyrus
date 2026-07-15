<?php

namespace App\Models\Lab;

use App\Casts\JsonObject;
use App\Models\Ancillary\AncillaryOrder;
use App\Models\Integration\Source;
use Database\Factories\Lab\ResultFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Result extends Model
{
    /** @use HasFactory<ResultFactory> */
    use HasFactory;

    protected $table = 'prod.lab_results';

    protected $primaryKey = 'lab_result_id';

    protected $guarded = [];

    protected $casts = [
        'auto_verified' => 'boolean',
        'is_critical' => 'boolean',
        'observed_at' => 'immutable_datetime',
        'resulted_at' => 'immutable_datetime',
        'verified_at' => 'immutable_datetime',
        'corrected_at' => 'immutable_datetime',
        'cancelled_at' => 'immutable_datetime',
        'metadata' => JsonObject::class,
    ];

    protected static function newFactory(): ResultFactory
    {
        return ResultFactory::new();
    }

    public function ancillaryOrder(): BelongsTo
    {
        return $this->belongsTo(AncillaryOrder::class, 'ancillary_order_id', 'ancillary_order_id');
    }

    public function specimen(): BelongsTo
    {
        return $this->belongsTo(Specimen::class, 'lab_specimen_id', 'lab_specimen_id');
    }

    public function testCatalog(): BelongsTo
    {
        return $this->belongsTo(LabTestCatalog::class, 'lab_test_catalog_id', 'lab_test_catalog_id');
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class, 'source_id', 'source_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_lab_result_id', 'lab_result_id');
    }

    public function corrections(): HasMany
    {
        return $this->hasMany(self::class, 'parent_lab_result_id', 'lab_result_id');
    }

    public function criticalValues(): HasMany
    {
        return $this->hasMany(CriticalValue::class, 'lab_result_id', 'lab_result_id');
    }

    public function scopePreliminary(Builder $query): Builder
    {
        return $query->where($this->qualifyColumn('result_status'), 'preliminary');
    }

    public function scopeFinalized(Builder $query): Builder
    {
        return $query->whereIn($this->qualifyColumn('result_status'), ['final', 'corrected']);
    }

    public function scopeCritical(Builder $query): Builder
    {
        return $query->where($this->qualifyColumn('is_critical'), true);
    }

    public function scopePendingVerification(Builder $query): Builder
    {
        return $query->whereIn($this->qualifyColumn('result_status'), ['preliminary', 'final'])
            ->whereNull($this->qualifyColumn('verified_at'));
    }

    public function scopeMicrobiology(Builder $query): Builder
    {
        return $query->whereHas('testCatalog', fn (Builder $catalog): Builder => $catalog->where('department', 'microbiology'));
    }
}
