<?php

namespace App\Http\Requests\Rtdc;

use Illuminate\Foundation\Http\FormRequest;

class UpsertCapacityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'service_date' => 'required|date',
            'horizon' => 'required|in:by_2pm,by_midnight',
            'definite' => 'required|integer|min:0|max:200',
            'probable' => 'required|integer|min:0|max:200',
            'possible' => 'required|integer|min:0|max:200',
        ];
    }
}
