<?php

namespace App\Http\Controllers\Api\Deployment\Staffing;

use App\Http\Controllers\Controller;
use App\Models\Reference\ServiceLine;
use App\Models\Reference\StaffRole;
use Illuminate\Http\JsonResponse;

/**
 * Phase F4 (§8): the option lists the wizard's edit + rule forms need — the service-line
 * registry and the staff-role taxonomy — under the same manageDeploymentConfig gate as
 * the rest of the write API (so the wizard is self-contained and never depends on the
 * broader viewDeploymentConsole read endpoints).
 */
class StaffReferenceController extends Controller
{
    public function index(): JsonResponse
    {
        $serviceLines = ServiceLine::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('service_line_code')
            ->get(['service_line_code', 'display_name', 'clinical_domain'])
            ->map(fn (ServiceLine $s): array => [
                'code' => $s->service_line_code,
                'name' => $s->display_name,
                'clinical_domain' => $s->clinical_domain,
            ])
            ->all();

        $roles = StaffRole::query()
            ->orderBy('sort_order')
            ->orderBy('role_code')
            ->get(['role_code', 'display_name', 'role_category', 'is_regulated', 'is_provider', 'is_nursing'])
            ->map(fn (StaffRole $r): array => [
                'role_code' => $r->role_code,
                'display_name' => $r->display_name,
                'role_category' => $r->role_category,
                'is_regulated' => (bool) $r->is_regulated,
                'is_provider' => (bool) $r->is_provider,
                'is_nursing' => (bool) $r->is_nursing,
            ])
            ->all();

        return response()->json(['data' => ['service_lines' => $serviceLines, 'roles' => $roles]]);
    }
}
