<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Auth\AuthProviderSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuthProviderController extends Controller
{
    private const SECRET_KEYS = ['client_secret'];

    public function show(string $type): JsonResponse
    {
        $this->authorizeAdmin();

        $row = AuthProviderSetting::query()->where('provider_type', $type)->first();

        return response()->json([
            'provider_type' => $type,
            'is_enabled' => (bool) ($row?->is_enabled ?? false),
            'display_name' => $row?->display_name,
            'settings' => $this->mask($row?->settings ?? []),
        ]);
    }

    public function update(Request $request, string $type): JsonResponse
    {
        $this->authorizeAdmin();

        $validated = $request->validate([
            'is_enabled' => 'sometimes|boolean',
            'display_name' => 'sometimes|string|max:255',
            'settings' => 'sometimes|array',
        ]);

        $row = AuthProviderSetting::query()->firstOrNew(['provider_type' => $type]);
        $merged = array_merge($row->settings ?? [], $validated['settings'] ?? []);
        foreach (self::SECRET_KEYS as $k) {
            unset($merged[$k]); // secrets live in env only, never the DB
        }

        $row->fill([
            'display_name' => $validated['display_name'] ?? $row->display_name ?? 'Sign in with Authentik',
            'is_enabled' => $validated['is_enabled'] ?? $row->is_enabled ?? false,
            'settings' => $merged,
            'updated_by' => $request->user()?->id,
        ])->save();

        return $this->show($type);
    }

    private function authorizeAdmin(): void
    {
        abort_unless(in_array(auth()->user()?->role, ['admin', 'superuser'], true), 403);
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    private function mask(array $settings): array
    {
        foreach (self::SECRET_KEYS as $k) {
            if (array_key_exists($k, $settings)) {
                $settings[$k] = '••••••••';
            }
        }

        return $settings;
    }
}
