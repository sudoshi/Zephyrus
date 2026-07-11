<?php

namespace App\Http\Requests\Rounds;

use Illuminate\Foundation\Http\FormRequest;

class ReorderQueueRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'order' => 'required|array|min:1',
            'order.*' => 'uuid',
            'expected_queue_version' => 'required|integer|min:1',
        ];
    }
}
