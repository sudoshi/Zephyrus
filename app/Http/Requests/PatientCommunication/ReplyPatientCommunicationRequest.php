<?php

namespace App\Http\Requests\PatientCommunication;

class ReplyPatientCommunicationRequest extends StaffMessageMutationRequest
{
    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();

        if (is_string($this->input('message'))) {
            $this->merge(['message' => trim($this->input('message'))]);
        }
    }

    public function rules(): array
    {
        return [
            'work_item_version' => ['required', 'integer', 'min:1'],
            'thread_version' => ['required', 'integer', 'min:1'],
            'message' => ['required', 'string', 'min:1', 'max:4000'],
            'client_message_uuid' => ['required', 'uuid'],
            'idempotency_key' => ['required', 'uuid'],
        ];
    }

    protected function allowedPayloadKeys(): array
    {
        return ['work_item_version', 'thread_version', 'message', 'client_message_uuid'];
    }

    protected function exactIntegerPayloadKeys(): array
    {
        return ['work_item_version', 'thread_version'];
    }

    protected function exactStringPayloadKeys(): array
    {
        return ['message', 'client_message_uuid'];
    }
}
