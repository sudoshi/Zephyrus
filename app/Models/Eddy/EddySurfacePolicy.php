<?php

namespace App\Models\Eddy;

use Illuminate\Database\Eloquent\Model;

/**
 * What a surface is *allowed* to use. Port of Parthenon's AbbySurfacePolicy.
 *
 * @property string $surface
 * @property string $provider_mode
 * @property string|null $default_profile_id
 * @property array<int, string> $fallback_profile_ids
 * @property bool $never_send_phi_to_cloud
 * @property bool $allow_cloud
 * @property array<int, string> $required_capabilities
 * @property array<string, mixed> $settings
 */
class EddySurfacePolicy extends Model
{
    protected $table = 'eddy.eddy_surface_policies';

    protected $guarded = [];

    protected $casts = [
        'fallback_profile_ids' => 'array',
        'never_send_phi_to_cloud' => 'boolean',
        'allow_cloud' => 'boolean',
        'required_capabilities' => 'array',
        'settings' => 'array',
    ];
}
