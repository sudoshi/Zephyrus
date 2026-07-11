<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Auth\AuthProviderSetting;
use App\Services\Audit\UserAuditRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class AuthProviderController extends Controller
{
    private const SECRET_KEYS = ['client_secret'];

    public function __construct(private readonly UserAuditRecorder $audit) {}

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

        DB::transaction(function () use ($request, $type, $validated): void {
            $row = AuthProviderSetting::query()->firstOrNew(['provider_type' => $type]);
            $beforeEnabled = $row->exists ? (bool) $row->is_enabled : null;
            $beforeDisplayName = $row->display_name;
            $beforeSettings = $row->settings ?? [];
            $merged = array_merge($beforeSettings, $validated['settings'] ?? []);
            foreach (self::SECRET_KEYS as $key) {
                unset($merged[$key]); // secrets live in env only, never the DB
            }

            $row->fill([
                'display_name' => $validated['display_name'] ?? $row->display_name ?? 'Sign in with Authentik',
                'is_enabled' => $validated['is_enabled'] ?? $row->is_enabled ?? false,
                'settings' => $merged,
                'updated_by' => $request->user()?->id,
            ])->save();

            $this->audit->record('administration.auth_provider.updated', 'administration', 'success', [
                'request' => $request,
                'target_type' => 'auth_provider',
                'target_id' => $type,
                'changes' => [
                    'provider_enabled' => ['from' => $beforeEnabled, 'to' => (bool) $row->is_enabled],
                    'display_name_changed' => ['from' => false, 'to' => $beforeDisplayName !== $row->display_name],
                    'settings_changed' => ['from' => false, 'to' => $beforeSettings !== $merged],
                ],
                'metadata' => [
                    'provider_type' => $type,
                    'changed_fields' => array_values(array_keys($validated)),
                ],
            ]);
        });

        return $this->show($type);
    }

    private function authorizeAdmin(): void
    {
        Gate::authorize('viewAdministration');
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
