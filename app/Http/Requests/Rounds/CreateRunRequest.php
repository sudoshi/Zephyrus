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
        // eddy_assisted is a valid DB/template mode; only offer it to clients
        // when the Eddy sub-feature is on (the DB CHECK and templates already
        // permit it — this keeps the request surface consistent with the flag).
        $modes = ['async', 'live', 'hybrid'];
        if (config('rounds.eddy_enabled')) {
            $modes[] = 'eddy_assisted';
        }

        return [
            'template_uuid' => 'required|uuid',
            'scope_type' => 'required|in:unit',
            'scope_key' => 'required|string|max:100',
            'mode' => 'nullable|in:'.implode(',', $modes),
            'planned_start_at' => 'nullable|date',
            'window_end_at' => 'nullable|date|after:planned_start_at',
        ];
    }
}
