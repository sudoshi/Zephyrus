<?php

namespace App\Http\Requests\Evs;

use Illuminate\Foundation\Http\FormRequest;

class CreateEvsRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'request_type' => 'required|in:bed_clean,room_clean,terminal_clean,isolation_clean,spill,discharge_turnover,procedure_turnover',
            'priority' => 'required|in:routine,urgent,stat',
            'room_id' => 'nullable|integer',
            'bed_id' => 'nullable|integer',
            'unit_id' => 'nullable|integer',
            'patient_ref' => 'nullable|string|max:120',
            'encounter_ref' => 'nullable|string|max:120',
            'location_label' => 'required|string|max:160',
            'turn_type' => 'required|in:standard,terminal,isolation,stat,procedure,spill',
            'isolation_required' => 'boolean',
            'requested_by' => 'nullable|string|max:120',
            'needed_at' => 'nullable|date',
            'assigned_team' => 'nullable|string|max:120',
            'assigned_user_ref' => 'nullable|string|max:120',
            'external_system' => 'nullable|string|max:120',
            'external_id' => 'nullable|string|max:160',
            'risk_flags' => 'nullable|array',
            'completion_payload' => 'nullable|array',
            'metadata' => 'nullable|array',
        ];
    }
}
