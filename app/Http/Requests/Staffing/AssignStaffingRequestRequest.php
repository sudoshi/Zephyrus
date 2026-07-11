<?php

namespace App\Http\Requests\Staffing;

use Illuminate\Foundation\Http\FormRequest;

class AssignStaffingRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'assigned_source' => 'required|in:float_pool,overtime,agency,on_call',
            'owner_name' => 'nullable|string|max:120',
        ];
    }
}
