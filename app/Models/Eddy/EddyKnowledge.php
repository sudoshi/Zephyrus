<?php

namespace App\Models\Eddy;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Institutional operational-knowledge / RAG store. Only is_phi_free rows are
 * cloud-eligible. Phase 2 = keyword/tag retrieval; Phase 6 adds pgvector.
 */
class EddyKnowledge extends Model
{
    protected $table = 'eddy.eddy_knowledge';

    protected $primaryKey = 'eddy_knowledge_id';

    protected $guarded = [];

    protected $casts = [
        'tags' => 'array',
        'curated_from' => 'array',
        'is_phi_free' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function scopeProposed(Builder $query): Builder
    {
        return $query->where('status', 'proposed');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopePhiFree(Builder $query): Builder
    {
        return $query->where('is_phi_free', true);
    }

    public function scopeForSurface(Builder $query, string $surface): Builder
    {
        return $query->whereIn('surface', [$surface, 'global']);
    }
}
