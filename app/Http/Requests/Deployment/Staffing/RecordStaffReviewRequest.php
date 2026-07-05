<?php

namespace App\Http\Requests\Deployment\Staffing;

use App\Services\Staffing\StaffImportStore;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Phase F4: record one reviewer decision on a staged staff member (§7 step 5).
 * The decision's registry/role validity (for edit/split) is checked in the
 * controller via StaffImportStore::validateDecision so a hand-edited assignment can
 * never persist an FK-invalid service line or role.
 */
class RecordStaffReviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route-gated by can:manageDeploymentConfig
    }

    public function rules(): array
    {
        return [
            'action' => ['required', Rule::in(StaffImportStore::ACTIONS)],
            'assignments' => ['nullable', 'array'],
            'assignments.*.service_line_code' => ['required_with:assignments', 'string'],
            'assignments.*.role_code' => ['required_with:assignments', 'string'],
            'assignments.*.unit_hint' => ['nullable', 'string', 'max:120'],
            'assignments.*.program_code' => ['nullable', 'string', 'max:80'],
            'assignments.*.primary' => ['nullable', 'boolean'],
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }
}
