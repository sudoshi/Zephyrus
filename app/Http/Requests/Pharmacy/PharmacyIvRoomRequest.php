<?php

namespace App\Http\Requests\Pharmacy;

use App\Services\Pharmacy\PharmacyIvRoomService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class PharmacyIvRoomRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'prepType' => ['sometimes', 'nullable', 'string', Rule::in(PharmacyIvRoomService::PREP_TYPES)],
        ];
    }
}
