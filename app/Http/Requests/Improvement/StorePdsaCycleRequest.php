<?php

namespace App\Http\Requests\Improvement;

use App\Models\Unit;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePdsaCycleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => 'required|string|max:255',
            'objective' => 'nullable|string|max:2000',
            'rationale' => 'nullable|string|max:2000',
            'prediction' => 'nullable|string|max:2000',
            'owner' => 'nullable|string|max:255',
            'dueDate' => 'nullable|date',
            'unit_id' => ['nullable', Rule::exists(Unit::class, 'unit_id')],
        ];
    }
}
