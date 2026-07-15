<?php

namespace App\Models\Pharmacy;

use App\Casts\JsonObject;
use App\Models\Integration\Source;
use Database\Factories\Pharmacy\VerificationFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Verification extends Model
{
    /** @use HasFactory<VerificationFactory> */
    use HasFactory;

    protected $table = 'prod.rx_verifications';

    protected $primaryKey = 'rx_verification_id';

    protected $guarded = [];

    protected $casts = [
        'queued_at' => 'immutable_datetime',
        'verified_at' => 'immutable_datetime',
        'removed_at' => 'immutable_datetime',
        'metadata' => JsonObject::class,
    ];

    protected static function newFactory(): VerificationFactory
    {
        return VerificationFactory::new();
    }

    public function medicationOrder(): BelongsTo
    {
        return $this->belongsTo(MedicationOrder::class, 'rx_order_id', 'rx_order_id');
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class, 'source_id', 'source_id');
    }

    public function scopeQueued(Builder $query): Builder
    {
        return $query->where($this->qualifyColumn('verification_state'), 'queued');
    }

    public function scopeVerified(Builder $query): Builder
    {
        return $query->where($this->qualifyColumn('verification_state'), 'verified');
    }
}
