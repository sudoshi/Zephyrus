<?php

namespace App\Models\Eddy;

use Illuminate\Database\Eloquent\Model;

/**
 * What a provider *can* do. Port of Parthenon's AbbyProviderProfile.
 * Secrets are never stored here — the api_key lives only in the Eddy service env.
 *
 * @property string $profile_id
 * @property string $display_name
 * @property string $provider_type
 * @property string $transport
 * @property string $entitlement_type
 * @property string $model
 * @property string|null $base_url
 * @property string|null $provider_setting_type
 * @property bool $is_enabled
 * @property array<int, string> $capabilities
 * @property array<string, mixed> $safety
 * @property array<string, mixed> $limits
 * @property array<int, string> $fallback_profile_ids
 * @property array<string, mixed> $notes
 */
class EddyProviderProfile extends Model
{
    protected $table = 'eddy.eddy_provider_profiles';

    protected $guarded = [];

    protected $casts = [
        'is_enabled' => 'boolean',
        'capabilities' => 'array',
        'safety' => 'array',
        'limits' => 'array',
        'fallback_profile_ids' => 'array',
        'notes' => 'array',
    ];
}
