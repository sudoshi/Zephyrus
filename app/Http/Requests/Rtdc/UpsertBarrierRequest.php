<?php

namespace App\Http\Requests\Rtdc;

use App\Models\Barrier;
use App\Models\Encounter;
use App\Models\Unit;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpsertBarrierRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'unit_id' => ['nullable', 'integer', Rule::exists(Unit::class, 'unit_id')],
            'encounter_id' => ['nullable', 'integer', Rule::exists(Encounter::class, 'encounter_id')],
            'category' => ['required', Rule::in(Barrier::CATEGORIES)],
            'reason_code' => 'nullable|string|max:50',
            'description' => 'nullable|string|max:500',
            'owner' => 'nullable|string|max:100',
        ];
    }
}
