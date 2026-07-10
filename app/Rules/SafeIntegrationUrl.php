<?php

namespace App\Rules;

use App\Security\Network\IntegrationUrlPolicy;
use App\Security\Network\UnsafeIntegrationUrl;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class SafeIntegrationUrl implements ValidationRule
{
    public function __construct(private readonly IntegrationUrlPolicy $policy) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $value === '') {
            return;
        }

        try {
            $this->policy->assertSafe($value);
        } catch (UnsafeIntegrationUrl $exception) {
            $fail($exception->getMessage());
        }
    }
}
