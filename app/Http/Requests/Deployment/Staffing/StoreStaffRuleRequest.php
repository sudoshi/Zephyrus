<?php

namespace App\Http\Requests\Deployment\Staffing;

use App\Models\Org\StaffingSource;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Phase F4: promote a reviewer decision to a deterministic crosswalk rule (§7 step 5,
 * §14 "rule promotion shrinks the queue"). The target service line is canonicalized +
 * registry-validated in the controller before insert (the column carries an FK), and
 * the role must exist in the taxonomy.
 */
class StoreStaffRuleRequest extends FormRequest
{
    public const MATCH_FIELDS = ['cost_center', 'department', 'specialty', 'job_code', 'job_title', 'home_unit'];

    public const OPERATORS = ['equals', 'prefix', 'contains', 'regex'];

    public function authorize(): bool
    {
        return true; // route-gated by can:manageDeploymentConfig
    }

    public function rules(): array
    {
        return [
            'staffing_source_id' => ['nullable', 'integer', Rule::exists(StaffingSource::class, 'staffing_source_id')],
            'match_field' => ['required', Rule::in(self::MATCH_FIELDS)],
            'match_operator' => ['nullable', Rule::in(self::OPERATORS)],
            'match_value' => ['required', 'string', 'max:200'],
            'target_service_line_code' => ['required', 'string', 'max:80'],
            'target_role_code' => ['required', 'string', 'max:80'],
            'target_unit_hint' => ['nullable', 'string', 'max:120'],
            'priority' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'confidence' => ['nullable', 'numeric', 'min:0', 'max:1'],
            // Optional: link the promotion to the staged member it came from (audit trail).
            'staff_import_run_id' => ['nullable', 'integer'],
            'staff_member_id' => ['nullable', 'integer'],
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }
}
