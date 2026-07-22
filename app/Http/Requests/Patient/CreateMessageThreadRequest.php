<?php

namespace App\Http\Requests\Patient;

use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class CreateMessageThreadRequest extends FormRequest
{
    /** @var list<int|string> */
    private array $submittedJsonKeys = [];

    protected function prepareForValidation(): void
    {
        $this->submittedJsonKeys = array_keys($this->json()->all());
        $prepared = [];

        foreach (['topic_code', 'message', 'client_message_uuid', 'urgent_guidance_version'] as $key) {
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
            'topic_code' => ['required', 'string', 'max:80', 'regex:/^[a-z][a-z0-9_]{1,78}[a-z0-9]$/'],
            'message' => ['required', 'string', 'min:1', 'max:2000', $this->safeTextRule()],
            'client_message_uuid' => ['required', 'uuid'],
            'urgent_guidance_version' => ['required', 'string', 'max:120'],
            'idempotency_key' => ['required', 'uuid'],
        ];
    }

    /** @return array<int, callable> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $allowed = ['topic_code', 'message', 'client_message_uuid', 'urgent_guidance_version'];

            foreach (array_diff($this->submittedJsonKeys, $allowed) as $key) {
                $validator->errors()->add(
                    is_string($key) ? $key : 'request_body',
                    'Unknown JSON properties are not allowed.',
                );
            }
        }];
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
