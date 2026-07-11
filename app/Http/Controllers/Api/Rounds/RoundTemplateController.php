<?php

namespace App\Http\Controllers\Api\Rounds;

use App\Models\Rounds\RoundTemplate;
use Illuminate\Http\JsonResponse;

class RoundTemplateController extends RoundsController
{
    public function index(): JsonResponse
    {
        $templates = RoundTemplate::query()
            ->active()
            ->orderBy('name')
            ->get()
            ->map(fn (RoundTemplate $t) => [
                'template_uuid' => $t->template_uuid,
                'name' => $t->name,
                'description' => $t->description,
                'scope_types' => $t->scopeTypes(),
                'mode' => $t->mode,
                'required_roles' => $t->required_roles,
                'version' => $t->version,
            ]);

        // The section allowlist and role catalog are config-owned; the client
        // renders from this, never from a hardcoded copy (no drift).
        $sections = collect((array) config('rounds.sections'))->map(fn ($s, $code) => [
            'section_code' => $code,
            'label' => $s['label'],
            'roles' => $s['roles'],
            'fields' => $s['fields'],
        ])->values();

        return response()->json([
            'data' => $templates,
            'meta' => [
                'sections' => $sections,
                'roles' => (object) config('rounds.roles'),
            ],
        ]);
    }
}
