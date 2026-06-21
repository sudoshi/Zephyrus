<?php

namespace App\Http\Requests\Rtdc;

use App\Models\Bed;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BedPlacementDecisionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'action' => 'required|in:accepted,edited,rejected',
            'chosen_bed_id' => ['nullable', Rule::exists(Bed::class, 'bed_id')],
            'reason' => 'nullable|string|max:500',
        ];
    }
}
