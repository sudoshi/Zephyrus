<?php

namespace App\Http\Requests\Rounds;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class DeferPatientRoundQuestionRequest extends FormRequest
{
    /** @var list<int|string> */
    private array $submittedJsonKeys = [];

    protected function prepareForValidation(): void
    {
        $this->submittedJsonKeys = array_keys($this->json()->all());
        $idempotencyKey = $this->header('Idempotency-Key');

        $this->merge([
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
            'idempotency_key' => ['required', 'uuid'],
        ];
    }

    /** @return array<int, callable> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach ($this->submittedJsonKeys as $key) {
                $validator->errors()->add(
                    is_string($key) ? $key : 'request_body',
                    'This action does not accept JSON properties.',
                );
            }
        }];
    }
}
