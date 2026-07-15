<?php

namespace App\Http\Requests\Pharmacy;

use Illuminate\Foundation\Http\FormRequest;

final class PharmacyDispenseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'stationType' => ['sometimes', 'nullable', 'string', 'max:24'],
            'forecast' => ['sometimes', 'boolean'],
        ];
    }
}
