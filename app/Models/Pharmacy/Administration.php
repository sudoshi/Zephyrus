<?php

namespace App\Models\Pharmacy;

use App\Casts\JsonObject;
use App\Models\Integration\Source;
use Database\Factories\Pharmacy\AdministrationFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Administration extends Model
{
    /** @use HasFactory<AdministrationFactory> */
    use HasFactory;

    protected $table = 'prod.rx_administrations';

    protected $primaryKey = 'rx_administration_id';

    protected $guarded = [];

    protected $casts = [
        'administered_at' => 'immutable_datetime',
        'source_cutoff_at' => 'immutable_datetime',
        'metadata' => JsonObject::class,
    ];

    protected static function newFactory(): AdministrationFactory
    {
        return AdministrationFactory::new();
    }

    public function medicationOrder(): BelongsTo
    {
        return $this->belongsTo(MedicationOrder::class, 'rx_order_id', 'rx_order_id');
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class, 'source_id', 'source_id');
    }

    public function scopeGiven(Builder $query): Builder
    {
        return $query->where($this->qualifyColumn('administration_status'), 'given');
    }

    public function scopeFreshSince(Builder $query, mixed $cutoff): Builder
    {
        return $query->where($this->qualifyColumn('source_cutoff_at'), '>=', $cutoff);
    }

    public function scopeForImportBatch(Builder $query, string $importBatchKey): Builder
    {
        return $query->where($this->qualifyColumn('import_batch_key'), $importBatchKey);
    }
}
