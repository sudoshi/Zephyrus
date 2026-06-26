<?php

namespace App\Http\Requests\Staffing;

use Illuminate\Foundation\Http\FormRequest;

class CreateStaffingRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'unit_id' => 'nullable|integer',
            'staffing_plan_id' => 'nullable|integer',
            'unit_label' => 'required|string|max:120',
            'role' => 'required|in:rn,lpn,tech,charge,provider,respiratory,unit_secretary',
            'shift_date' => 'nullable|date',
            'shift' => 'required|in:day,evening,night',
            'request_type' => 'required|in:fill_gap,float,overtime,agency,on_call,reassign',
            'priority' => 'required|in:routine,urgent,stat',
            'headcount_needed' => 'required|integer|min:1|max:50',
            'hours_needed' => 'nullable|numeric|min:0|max:24',
            'requested_by' => 'nullable|string|max:120',
            'needed_by' => 'nullable|date',
            'owner_name' => 'nullable|string|max:120',
            'risk_flags' => 'nullable|array',
            'metadata' => 'nullable|array',
        ];
    }
}
