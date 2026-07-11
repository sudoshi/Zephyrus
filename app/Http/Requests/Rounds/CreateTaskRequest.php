<?php

namespace App\Http\Requests\Rounds;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateTaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'title' => 'required|string|max:300',
            'detail' => 'nullable|string|max:4000',
            'category' => 'nullable|string|max:60',
            'owner_role' => ['nullable', 'string', Rule::in(array_keys((array) config('rounds.roles')))],
            'owner_user_id' => 'nullable|integer',
            'due_at' => 'nullable|date',
        ];
    }
}
