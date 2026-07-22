<?php

namespace App\Http\Requests\Rounds;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class PromotePatientRoundQuestionRequest extends FormRequest
{
    /** @var list<int|string> */
    private array $submittedJsonKeys = [];

    protected function prepareForValidation(): void
    {
        $this->submittedJsonKeys = array_keys($this->json()->all());
        $idempotencyKey = $this->header('Idempotency-Key');

        $this->merge([
            'message_uuid' => trim((string) $this->input('message_uuid')),
            'thread_version' => $this->input('thread_version'),
            'idempotency_key' => is_string($idempotencyKey) ? trim($idempotencyKey) : null,
        ]);
    }

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'message_uuid' => ['required', 'uuid'],
            'thread_version' => ['required', 'integer', 'min:1'],
            'idempotency_key' => ['required', 'uuid'],
        ];
    }

    /** @return array<int, callable> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (array_diff($this->submittedJsonKeys, ['message_uuid', 'thread_version']) as $key) {
                $validator->errors()->add(
                    is_string($key) ? $key : 'request_body',
                    'Unknown JSON properties are not allowed.',
                );
            }
        }];
    }
}
