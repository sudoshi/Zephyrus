<?php

namespace App\Models\Fhir;

use App\Models\Integration\Source;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ResourceLink extends Model
{
    protected $table = 'fhir.resource_links';

    protected $primaryKey = 'resource_link_id';

    protected $guarded = [];

    protected $casts = [
        'metadata' => 'array',
    ];

    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class, 'source_id', 'source_id');
    }
}
