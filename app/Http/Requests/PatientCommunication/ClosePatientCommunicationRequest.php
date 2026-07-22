<?php

namespace App\Http\Requests\PatientCommunication;

use Illuminate\Validation\Rule;

class ClosePatientCommunicationRequest extends StaffMessageMutationRequest
{
    public function rules(): array
    {
        return [
            'work_item_version' => ['required', 'integer', 'min:1'],
            'thread_version' => ['required', 'integer', 'min:1'],
            'reason_code' => [
                'required',
                'string',
                Rule::in(['question_answered', 'duplicate', 'transferred', 'patient_requested', 'other']),
            ],
            'idempotency_key' => ['required', 'uuid'],
        ];
    }

    protected function allowedPayloadKeys(): array
    {
        return ['work_item_version', 'thread_version', 'reason_code'];
    }

    protected function exactIntegerPayloadKeys(): array
    {
        return ['work_item_version', 'thread_version'];
    }

    protected function exactStringPayloadKeys(): array
    {
        return ['reason_code'];
    }
}
