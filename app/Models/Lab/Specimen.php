<?php

namespace App\Models\Lab;

use App\Casts\JsonObject;
use App\Models\Ancillary\AncillaryOrder;
use App\Models\Encounter;
use App\Models\Integration\Source;
use Database\Factories\Lab\SpecimenFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Specimen extends Model
{
    /** @use HasFactory<SpecimenFactory> */
    use HasFactory;

    protected $table = 'prod.lab_specimens';

    protected $primaryKey = 'lab_specimen_id';

    protected $guarded = [];

    protected $casts = [
        'collected_at' => 'immutable_datetime',
        'in_transit_at' => 'immutable_datetime',
        'received_at' => 'immutable_datetime',
        'rejected_at' => 'immutable_datetime',
        'recollect_ordered_at' => 'immutable_datetime',
        'cancelled_at' => 'immutable_datetime',
        'metadata' => JsonObject::class,
    ];

    protected static function newFactory(): SpecimenFactory
    {
        return SpecimenFactory::new();
    }

    public function ancillaryOrder(): BelongsTo
    {
        return $this->belongsTo(AncillaryOrder::class, 'ancillary_order_id', 'ancillary_order_id');
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class, 'source_id', 'source_id');
    }

    public function encounter(): BelongsTo
    {
        return $this->belongsTo(Encounter::class, 'encounter_id', 'encounter_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_specimen_id', 'lab_specimen_id');
    }

    public function recollects(): HasMany
    {
        return $this->hasMany(self::class, 'parent_specimen_id', 'lab_specimen_id');
    }

    public function results(): HasMany
    {
        return $this->hasMany(Result::class, 'lab_specimen_id', 'lab_specimen_id');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNotIn($this->qualifyColumn('status'), ['received', 'rejected', 'cancelled']);
    }

    public function scopePendingCollection(Builder $query): Builder
    {
        return $query->where($this->qualifyColumn('status'), 'collection_pending')->whereNull($this->qualifyColumn('collected_at'));
    }

    public function scopePendingReceipt(Builder $query): Builder
    {
        return $query->whereIn($this->qualifyColumn('status'), ['collected', 'in_transit'])->whereNull($this->qualifyColumn('received_at'));
    }

    public function scopeRejected(Builder $query): Builder
    {
        return $query->whereIn($this->qualifyColumn('status'), ['rejected', 'recollect_requested']);
    }
}
