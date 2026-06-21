<?php

namespace App\Http\Requests\Rtdc;

use Illuminate\Foundation\Http\FormRequest;

class UpsertDemandRequest extends FormRequest
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
            'ed' => 'required|integer|min:0|max:500',
            'or' => 'required|integer|min:0|max:500',
            'transfer' => 'required|integer|min:0|max:500',
            'direct' => 'required|integer|min:0|max:500',
        ];
    }
}
