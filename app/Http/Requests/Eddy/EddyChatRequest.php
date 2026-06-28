<?php

namespace App\Http\Requests\Eddy;

use Illuminate\Foundation\Http\FormRequest;

class EddyChatRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Route is already behind web+auth; any authenticated user may chat.
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'message' => ['required', 'string', 'max:8000'],
            'surface' => ['nullable', 'string', 'max:80'],
            'page_context' => ['nullable', 'string', 'max:120'],
            'page_component' => ['nullable', 'string', 'max:160'],
            'page_data' => ['nullable', 'array'],
            'conversation_id' => ['nullable', 'uuid'],
        ];
    }
}
