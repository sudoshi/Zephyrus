<?php

namespace App\Http\Requests\Patient;

use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class CloseMessageThreadRequest extends FormRequest
{
    /** @var list<int|string> */
    private array $submittedJsonKeys = [];

    protected function prepareForValidation(): void
    {
        $this->submittedJsonKeys = array_keys($this->json()->all());
        $prepared = [];
        $closeReason = $this->input('close_reason');
        if (is_string($closeReason)) {
            $prepared['close_reason'] = trim($closeReason);
        }

        $idempotencyKey = $this->header('Idempotency-Key');
        if (is_string($idempotencyKey)) {
            $prepared['idempotency_key'] = trim($idempotencyKey);
        }

        $this->merge($prepared);
    }

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'thread_version' => ['required', $this->exactIntegerRule(), 'integer', 'min:1'],
            'close_reason' => [
                'required',
                'string',
                Rule::in(['question_answered', 'no_longer_needed', 'created_in_error', 'other']),
            ],
            'idempotency_key' => ['required', 'uuid'],
        ];
    }

    /** @return array<int, callable> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $allowed = ['thread_version', 'close_reason'];

            foreach (array_diff($this->submittedJsonKeys, $allowed) as $key) {
                $validator->errors()->add(
                    is_string($key) ? $key : 'request_body',
                    'Unknown JSON properties are not allowed.',
                );
            }
        }];
    }

    private function exactIntegerRule(): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_int($value)) {
                $fail("The {$attribute} must be a JSON integer.");
            }
        };
    }
}
