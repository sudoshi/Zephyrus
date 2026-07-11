<?php

namespace App\Http\Requests\Rounds;

use Illuminate\Foundation\Http\FormRequest;

class TransitionPatientRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'expected_version' => 'nullable|integer|min:1',
            'reason' => 'nullable|string|max:1000',
            'exception_reason' => 'nullable|string|max:1000',
        ];
    }
}
