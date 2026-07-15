<?php

namespace App\Models\Radiology;

use App\Casts\JsonObject;
use App\Models\Integration\Source;
use Database\Factories\Radiology\ScannerDowntimeFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScannerDowntime extends Model
{
    /** @use HasFactory<ScannerDowntimeFactory> */
    use HasFactory;

    protected $table = 'prod.rad_scanner_downtimes';

    protected $primaryKey = 'rad_scanner_downtime_id';

    protected $guarded = [];

    protected $casts = ['starts_at' => 'immutable_datetime', 'ends_at' => 'immutable_datetime', 'metadata' => JsonObject::class];

    protected static function newFactory(): ScannerDowntimeFactory
    {
        return ScannerDowntimeFactory::new();
    }

    public function scanner(): BelongsTo
    {
        return $this->belongsTo(Scanner::class, 'rad_scanner_id', 'rad_scanner_id');
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class, 'source_id', 'source_id');
    }

    public function scopeActiveAt(Builder $query, mixed $at): Builder
    {
        return $query->where('starts_at', '<=', $at)->where(fn (Builder $window): Builder => $window->whereNull('ends_at')->orWhere('ends_at', '>', $at));
    }
}
