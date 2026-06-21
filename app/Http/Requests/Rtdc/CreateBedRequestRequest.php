<?php

namespace App\Http\Requests\Rtdc;

use Illuminate\Foundation\Http\FormRequest;

class CreateBedRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'patient_ref' => 'required|string|max:100',
            'source' => 'required|in:ed,transfer,direct,or',
            'sex' => 'nullable|in:M,F,other',
            'service' => 'nullable|string|max:100',
            'acuity_tier' => 'required|integer|min:1|max:4',
            'isolation_required' => 'required|in:none,contact,droplet,airborne',
            'required_unit_type' => 'required|in:any,med_surg,icu,step_down',
        ];
    }
}
