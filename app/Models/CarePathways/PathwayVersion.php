<?php

namespace App\Models\CarePathways;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PathwayVersion extends Model
{
    protected $table = 'care_pathways.versions';

    protected $primaryKey = 'pathway_version_id';

    protected $guarded = [];

    protected $casts = [
        'unresolved_flags' => 'array',
        'raw_snapshot' => 'array',
        'effective_start' => 'immutable_date',
        'effective_end' => 'immutable_date',
    ];

    public function getRouteKeyName(): string
    {
        return 'pathway_version_uuid';
    }

    public function definition(): BelongsTo
    {
        return $this->belongsTo(PathwayDefinition::class, 'pathway_definition_id');
    }

    public function release(): BelongsTo
    {
        return $this->belongsTo(CatalogRelease::class, 'catalog_release_id');
    }

    public function sections(): HasMany
    {
        return $this->hasMany(PathwaySection::class, 'pathway_version_id');
    }

    public function drgMappings(): HasMany
    {
        return $this->hasMany(DrgMapping::class, 'pathway_version_id');
    }

    public function evidenceClaims(): HasMany
    {
        return $this->hasMany(EvidenceClaim::class, 'pathway_version_id');
    }

    public function milestones(): HasMany
    {
        return $this->hasMany(MilestoneDefinition::class, 'pathway_version_id');
    }

    public function activities(): HasMany
    {
        return $this->hasMany(ActivityDefinition::class, 'pathway_version_id');
    }

    public function goals(): HasMany
    {
        return $this->hasMany(GoalDefinition::class, 'pathway_version_id');
    }

    public function education(): HasMany
    {
        return $this->hasMany(EducationDefinition::class, 'pathway_version_id');
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(PathwayReview::class, 'pathway_version_id');
    }

    public function approvals(): HasMany
    {
        return $this->hasMany(PathwayApproval::class, 'pathway_version_id');
    }
}
