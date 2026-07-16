<?php

namespace App\Models\Radiology;

use App\Casts\JsonObject;
use App\Models\Ancillary\AncillaryOrder;
use App\Models\Encounter;
use App\Models\Integration\Source;
use Database\Factories\Radiology\ExamFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Exam extends Model
{
    /** @use HasFactory<ExamFactory> */
    use HasFactory;

    protected $table = 'prod.rad_exams';

    protected $primaryKey = 'rad_exam_id';

    protected $guarded = [];

    protected $casts = [
        'is_portable' => 'boolean', 'is_ir' => 'boolean',
        'scheduled_start_at' => 'immutable_datetime', 'scheduled_end_at' => 'immutable_datetime',
        'started_at' => 'immutable_datetime', 'completed_at' => 'immutable_datetime', 'cancelled_at' => 'immutable_datetime',
        'preparation' => JsonObject::class, 'metadata' => JsonObject::class,
    ];

    protected static function newFactory(): ExamFactory
    {
        return ExamFactory::new();
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

    public function modality(): BelongsTo
    {
        return $this->belongsTo(Modality::class, 'modality_code', 'code');
    }

    public function subspecialty(): BelongsTo
    {
        return $this->belongsTo(Subspecialty::class, 'subspecialty_code', 'code');
    }

    public function scanner(): BelongsTo
    {
        return $this->belongsTo(Scanner::class, 'rad_scanner_id', 'rad_scanner_id');
    }

    public function reads(): HasMany
    {
        return $this->hasMany(Read::class, 'rad_exam_id', 'rad_exam_id');
    }

    public function criticalResults(): HasMany
    {
        return $this->hasMany(CriticalResult::class, 'rad_exam_id', 'rad_exam_id');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNotIn($this->qualifyColumn('status'), ['complete', 'cancelled', 'discontinued']);
    }

    public function scopeUnread(Builder $query): Builder
    {
        return $query->where('status', 'complete')->whereDoesntHave('reads', fn (Builder $reads): Builder => $reads->whereIn('status', ['final', 'corrected', 'addendum']));
    }
}
