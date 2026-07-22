<?php

namespace App\Http\Requests\PatientCommunication;

class ClaimPatientCommunicationRequest extends StaffMessageMutationRequest
{
    public function rules(): array
    {
        return [
            'work_item_version' => ['required', 'integer', 'min:1'],
            'thread_version' => ['required', 'integer', 'min:1'],
            'idempotency_key' => ['required', 'uuid'],
        ];
    }

    protected function allowedPayloadKeys(): array
    {
        return ['work_item_version', 'thread_version'];
    }

    protected function exactIntegerPayloadKeys(): array
    {
        return ['work_item_version', 'thread_version'];
    }
}
