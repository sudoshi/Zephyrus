<?php

namespace App\Models\Lab;

use App\Casts\JsonObject;
use Database\Factories\Lab\LabTestCatalogFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LabTestCatalog extends Model
{
    /** @use HasFactory<LabTestCatalogFactory> */
    use HasFactory;

    protected $table = 'hosp_ref.lab_test_catalog';

    protected $primaryKey = 'lab_test_catalog_id';

    protected $guarded = [];

    protected $casts = [
        'is_active' => 'boolean',
        'effective_from' => 'immutable_datetime',
        'effective_to' => 'immutable_datetime',
        'metadata' => JsonObject::class,
    ];

    protected static function newFactory(): LabTestCatalogFactory
    {
        return LabTestCatalogFactory::new();
    }

    public function results(): HasMany
    {
        return $this->hasMany(Result::class, 'lab_test_catalog_id', 'lab_test_catalog_id');
    }

    public function scopeActiveAt(Builder $query, mixed $at): Builder
    {
        return $query
            ->where($this->qualifyColumn('is_active'), true)
            ->where($this->qualifyColumn('effective_from'), '<=', $at)
            ->where(function (Builder $range) use ($at): void {
                $range->whereNull($this->qualifyColumn('effective_to'))
                    ->orWhere($this->qualifyColumn('effective_to'), '>', $at);
            });
    }

    public function scopeDecisionClass(Builder $query, string|array $classes): Builder
    {
        return $query->whereIn($this->qualifyColumn('decision_class'), (array) $classes);
    }
}
