<?php

namespace App\Http\Requests\Rounds;

use Illuminate\Foundation\Http\FormRequest;

class PinPatientRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'pinned' => 'required|boolean',
            'reason' => 'required|string|max:1000',
            'expected_queue_version' => 'required|integer|min:1',
        ];
    }
}
