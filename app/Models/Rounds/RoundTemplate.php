<?php

namespace App\Models\Rounds;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RoundTemplate extends Model
{
    protected $table = 'rounds.templates';

    protected $primaryKey = 'template_id';

    protected $fillable = [
        'template_uuid', 'name', 'description', 'scope_types', 'mode',
        'required_roles', 'completion_policy', 'priority_policy', 'eta_policy',
        'version', 'active', 'created_by', 'metadata',
    ];

    protected $casts = [
        'required_roles' => 'array',
        'completion_policy' => 'array',
        'priority_policy' => 'array',
        'eta_policy' => 'array',
        'metadata' => 'array',
        'version' => 'integer',
        'active' => 'boolean',
        'created_by' => 'integer',
    ];

    public function runs(): HasMany
    {
        return $this->hasMany(RoundRun::class, 'template_id', 'template_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', true);
    }

    /**
     * scope_types is a Postgres text[]; expose it as a PHP list.
     *
     * @return list<string>
     */
    public function scopeTypes(): array
    {
        $raw = $this->getAttribute('scope_types');

        if (is_array($raw)) {
            return $raw;
        }

        // Postgres array literal: {unit,service_line}
        return array_values(array_filter(explode(',', trim((string) $raw, '{}'))));
    }
}
