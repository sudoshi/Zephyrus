<?php

namespace App\Http\Requests\Transport;

use Illuminate\Foundation\Http\FormRequest;

class CreateTransportRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('requestTransportOperations') === true;
    }

    public function rules(): array
    {
        return [
            'request_type' => 'required|in:inpatient,transfer,discharge,ems,care_transition',
            'priority' => 'required|in:routine,urgent,stat',
            'patient_ref' => 'required|string|max:100',
            'encounter_ref' => 'nullable|string|max:100',
            'origin' => 'required|string|max:160',
            'destination' => 'required|string|max:160',
            'transport_mode' => 'required|in:ambulatory,wheelchair,stretcher,bed,rideshare,nemt,bls,als,critical_care,ems,air,courier',
            'clinical_service' => 'nullable|string|max:120',
            'needed_at' => 'nullable|date',
            'external_system' => 'nullable|string|max:120',
            'external_id' => 'nullable|string|max:160',
            'segments' => 'nullable|array',
            'risk_flags' => 'nullable|array',
            'metadata' => 'nullable|array',
        ];
    }
}
