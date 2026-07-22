<?php

namespace App\Http\Requests\PatientCommunication;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

abstract class StaffMessageMutationRequest extends FormRequest
{
    /** @var array<string, mixed> */
    private array $originalJsonPayload = [];

    public function authorize(): bool
    {
        return true;
    }

    /** @return list<string> */
    abstract protected function allowedPayloadKeys(): array;

    /** @return list<string> */
    protected function exactIntegerPayloadKeys(): array
    {
        return [];
    }

    /** @return list<string> */
    protected function exactStringPayloadKeys(): array
    {
        return [];
    }

    /** @return list<string> */
    protected function canonicalUuidPayloadKeys(): array
    {
        return [];
    }

    protected function prepareForValidation(): void
    {
        if ($this->isJson()) {
            $this->originalJsonPayload = $this->json()->all();
        }

        $header = $this->header('Idempotency-Key');
        if (is_string($header)) {
            $this->merge(['idempotency_key' => trim($header)]);
        }
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (! $this->isJson()) {
                $validator->errors()->add('request', 'The request body must be JSON.');

                return;
            }

            $payload = $this->originalJsonPayload;
            $unknown = array_values(array_diff(array_keys($payload), $this->allowedPayloadKeys()));
            if ($unknown !== []) {
                $validator->errors()->add('request', 'The request contains unsupported properties.');
            }

            foreach ($this->exactIntegerPayloadKeys() as $key) {
                if (array_key_exists($key, $payload) && ! is_int($payload[$key])) {
                    $validator->errors()->add($key, "The {$key} field must be a JSON integer.");
                }
            }

            foreach ($this->exactStringPayloadKeys() as $key) {
                if (array_key_exists($key, $payload) && ! is_string($payload[$key])) {
                    $validator->errors()->add($key, "The {$key} field must be a JSON string.");
                }
            }

            $idempotencyKey = $this->input('idempotency_key');
            if (is_string($idempotencyKey) && ! $this->isCanonicalUuid($idempotencyKey)) {
                $validator->errors()->add(
                    'idempotency_key',
                    'The Idempotency-Key header must be a canonical lowercase UUID.',
                );
            }

            foreach ($this->canonicalUuidPayloadKeys() as $key) {
                if (array_key_exists($key, $payload)
                    && is_string($payload[$key])
                    && ! $this->isCanonicalUuid($payload[$key])
                ) {
                    $validator->errors()->add($key, "The {$key} field must be a canonical lowercase UUID.");
                }
            }
        });
    }

    private function isCanonicalUuid(string $value): bool
    {
        return preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D',
            $value,
        ) === 1;
    }
}
