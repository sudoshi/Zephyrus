<?php

namespace App\Http\Requests\Eddy;

use App\Services\Eddy\EddyActionService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class EddyProposeActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'action_type' => ['required', 'string', Rule::in(array_keys(EddyActionService::CATALOG))],
            'title' => ['nullable', 'string', 'max:300'],
            'surface' => ['nullable', 'string', 'max:80'],
            'scope_key' => ['nullable', 'string', 'max:160'],
            'rationale' => ['nullable', 'string', 'max:2000'],
            'runner_up' => ['nullable', 'string', 'max:1000'],
            'params' => ['nullable', 'array'],
            'expected_impact' => ['nullable', 'array'],
            'approve' => ['nullable', 'boolean'],
        ];
    }
}
