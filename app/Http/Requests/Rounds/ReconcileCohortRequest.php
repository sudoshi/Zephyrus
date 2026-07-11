<?php

namespace App\Http\Requests\Rounds;

use Illuminate\Foundation\Http\FormRequest;

class ReconcileCohortRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'add' => 'nullable|array|max:200',
            'add.*' => 'integer',
            'remove' => 'nullable|array|max:200',
            'remove.*' => 'uuid',
            'reason' => 'nullable|string|max:1000',
        ];
    }
}
