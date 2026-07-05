<?php

namespace App\Http\Controllers\Api\Deployment\Staffing;

use App\Http\Controllers\Controller;
use App\Http\Requests\Deployment\Staffing\StoreStaffRuleRequest;
use App\Models\Org\StaffMappingReview;
use App\Models\Org\StaffMappingRule;
use App\Models\Reference\StaffRole;
use App\Services\Deployment\ServiceLineNormalizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Phase F4 (§8): the deterministic crosswalk rules the resolver applies. Promoting a
 * reviewer decision to a rule is how the wizard learns — the next re-resolve moves
 * matching people out of the review queue (§14). Gated by manageDeploymentConfig.
 */
class StaffMappingRuleController extends Controller
{
    public function __construct(private readonly ServiceLineNormalizer $normalizer) {}

    public function index(Request $request): JsonResponse
    {
        $rules = StaffMappingRule::query()
            ->when($request->filled('staffing_source_id'), fn ($q) => $q->where('staffing_source_id', $request->integer('staffing_source_id')))
            ->orderBy('priority')
            ->orderBy('staff_mapping_rule_id')
            ->get()
            ->map(fn (StaffMappingRule $r): array => $this->present($r))
            ->all();

        return response()->json(['data' => $rules, 'meta' => ['count' => count($rules)]]);
    }

    public function store(StoreStaffRuleRequest $request): JsonResponse
    {
        $data = $request->validated();

        // Canonicalize + registry-validate the target service line (the column carries an FK).
        $serviceLine = $this->normalizer->canonical((string) $data['target_service_line_code']);
        if (! $this->normalizer->isKnown($serviceLine)) {
            return response()->json(['message' => "Unknown service line '{$data['target_service_line_code']}'."], 422);
        }

        if (StaffRole::find($data['target_role_code']) === null) {
            return response()->json(['message' => "Unknown role '{$data['target_role_code']}'."], 422);
        }

        $rule = StaffMappingRule::create([
            'staffing_source_id' => $data['staffing_source_id'] ?? null,
            'match_field' => $data['match_field'],
            'match_operator' => $data['match_operator'] ?? 'equals',
            'match_value' => $data['match_value'],
            'target_service_line_code' => $serviceLine,
            'target_role_code' => $data['target_role_code'],
            'target_unit_hint' => $data['target_unit_hint'] ?? null,
            'priority' => $data['priority'] ?? 100,
            'confidence' => $data['confidence'] ?? 0.90,
            'is_active' => true,
            'created_by' => $request->user()?->id,
        ]);

        // Optional audit trail: link the promotion to the staged member it came from.
        if (! empty($data['staff_import_run_id']) && ! empty($data['staff_member_id'])) {
            StaffMappingReview::create([
                'staff_import_run_id' => $data['staff_import_run_id'],
                'staff_member_id' => $data['staff_member_id'],
                'proposed' => [],
                'final' => ['target_service_line_code' => $serviceLine, 'target_role_code' => $data['target_role_code']],
                'action' => 'edit',
                'reviewer_id' => $request->user()?->id,
                'note' => $data['note'] ?? 'Promoted to mapping rule.',
                'promoted_to_rule_id' => $rule->staff_mapping_rule_id,
            ]);
        }

        return response()->json(['data' => $this->present($rule->refresh())], 201);
    }

    /**
     * @return array<string,mixed>
     */
    private function present(StaffMappingRule $rule): array
    {
        return [
            'staff_mapping_rule_id' => (int) $rule->staff_mapping_rule_id,
            'staffing_source_id' => $rule->staffing_source_id !== null ? (int) $rule->staffing_source_id : null,
            'match_field' => $rule->match_field,
            'match_operator' => $rule->match_operator,
            'match_value' => $rule->match_value,
            'target_service_line_code' => $rule->target_service_line_code,
            'target_role_code' => $rule->target_role_code,
            'target_unit_hint' => $rule->target_unit_hint,
            'priority' => (int) $rule->priority,
            'confidence' => (float) $rule->confidence,
            'is_active' => (bool) $rule->is_active,
        ];
    }
}
