<?php

namespace App\Http\Requests\Rounds;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateQuestionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'question_text' => 'required|string|max:2000',
            'target_role' => ['nullable', 'string', Rule::in(array_keys((array) config('rounds.roles')))],
            'target_user_id' => 'nullable|integer',
            'due_at' => 'nullable|date',
        ];
    }
}
