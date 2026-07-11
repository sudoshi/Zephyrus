<?php

namespace App\Http\Requests\Rounds;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateContributionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'section_code' => ['required', 'string', Rule::in(array_keys((array) config('rounds.sections')))],
            'author_role' => ['nullable', 'string', Rule::in(array_keys((array) config('rounds.roles')))],
            'structured_data' => 'nullable|array',
            'summary' => 'nullable|string|max:2000',
            'source_refs' => 'nullable|array|max:50',
            'source_refs.*' => 'string|max:500',
            'submit' => 'nullable|boolean',
        ];
    }
}
