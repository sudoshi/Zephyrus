<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Unit;
use App\Services\Flow\FlowLensService;
use App\Services\Mobile\MobilePersonaCatalog;
use App\Support\Hospital\HospitalManifest;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

/**
 * Inertia props for the web 4D Navigator's persona lens — FLOW-WINDOW-PLAN
 * §7.3 ("Persona lens prop: page reads the user's role → same lens config →
 * layer defaults + inspector redaction — closes G7 on web").
 *
 * Mirrors EnforceFlowLens' resolution (?persona= → X-Hummingbird-Role →
 * user default) but degrades instead of 403ing: a page render is not an API
 * read. When no lens resolves (plain web user with no Hummingbird persona)
 * the prop is null and the component falls back to the full house view —
 * patient-level API reads stay guarded server-side by EnforceFlowLens.
 */
trait ResolvesFlowLens
{
    /** @return array<string, mixed>|null */
    protected function resolveFlowLens(Request $request): ?array
    {
        $personas = app(MobilePersonaCatalog::class);
        $lensService = app(FlowLensService::class);

        try {
            return $lensService->lensFor($personas->fromRequest($request));
        } catch (AuthorizationException) {
            // Requested persona unavailable to this user (or no persona at
            // all) — fall back to the user's own default persona, if any.
        }

        try {
            $allowed = $personas->allowedForUser($request->user());

            return $allowed === [] ? null : $lensService->lensFor($allowed[0]);
        } catch (AuthorizationException) {
            return null;
        }
    }

    /**
     * unit_id ↔ unit_code ↔ floor bridge so the client can join projection
     * items (unit_id / bed_id) against the /locations payload's unit_code.
     *
     * @return list<array{unit_id: int, unit_code: ?string, name: ?string, floor: ?int}>
     */
    protected function flowUnits(): array
    {
        if (! Schema::hasTable('prod.units')) {
            return [];
        }

        $manifest = app(HospitalManifest::class);

        return Unit::query()
            ->where('is_deleted', false)
            ->orderBy('unit_id')
            ->get(['unit_id', 'abbreviation', 'name'])
            ->map(function (Unit $unit) use ($manifest): array {
                $entry = $unit->abbreviation ? $manifest->unit($unit->abbreviation) : null;

                return [
                    'unit_id' => (int) $unit->unit_id,
                    'unit_code' => $unit->abbreviation,
                    'name' => $unit->name,
                    'floor' => isset($entry['floor']) ? (int) $entry['floor'] : null,
                ];
            })
            ->values()
            ->all();
    }
}
