<?php

namespace App\Http\Requests\Patient;

use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class AmendPatientMessageRequest extends FormRequest
{
    /** @var list<int|string> */
    private array $submittedJsonKeys = [];

    protected function prepareForValidation(): void
    {
        $this->submittedJsonKeys = array_keys($this->json()->all());
        $prepared = [];

        foreach (['action', 'message', 'client_message_uuid', 'urgent_guidance_version'] as $key) {
            $value = $this->input($key);
            if (is_string($value)) {
                $prepared[$key] = trim($value);
            }
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
            'action' => ['required', 'string', Rule::in(['correction', 'retraction'])],
            'message' => [
                'nullable',
                'string',
                'min:1',
                'max:2000',
                'required_if:action,correction',
                'prohibited_unless:action,correction',
                $this->safeTextRule(),
            ],
            'client_message_uuid' => ['required', 'uuid'],
            'thread_version' => ['required', $this->exactIntegerRule(), 'integer', 'min:1'],
            'urgent_guidance_version' => ['required', 'string', 'max:120'],
            'idempotency_key' => ['required', 'uuid'],
        ];
    }

    /** @return array<int, callable> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $allowed = [
                'action',
                'message',
                'client_message_uuid',
                'thread_version',
                'urgent_guidance_version',
            ];

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

    private function safeTextRule(): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_string($value) || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $value) === 1) {
                $fail("The {$attribute} contains unsupported control characters.");
            }
        };
    }
}
