<?php

namespace App\Http\Requests\Rounds;

use Illuminate\Foundation\Http\FormRequest;

class CreateRunRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'template_uuid' => 'required|uuid',
            'scope_type' => 'required|in:unit',
            'scope_key' => 'required|string|max:100',
            'mode' => 'nullable|in:async,live,hybrid',
            'planned_start_at' => 'nullable|date',
            'window_end_at' => 'nullable|date|after:planned_start_at',
        ];
    }
}
