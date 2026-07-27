<?php

namespace App\Services\Patient;

use Illuminate\Contracts\Foundation\Application;
use RuntimeException;

class PatientHmac
{
    private const TESTING_ONLY_SECRET = 'hummingbird-patient-test-only-hmac-secret-v1';

    public function __construct(private readonly Application $app) {}

    public function digest(string $purpose, string $value): string
    {
        if (! preg_match('/^[a-z][a-z0-9._-]{1,79}$/', $purpose)) {
            throw new RuntimeException('patient_hmac_purpose_invalid');
        }

        return hash_hmac('sha256', $purpose."\0".$value, $this->secret());
    }

    public function assertAvailable(): void
    {
        $this->secret();
    }

    private function secret(): string
    {
        $configured = trim((string) config('hummingbird-patient.hmac_secret'));

        if (strlen($configured) >= 32) {
            return $configured;
        }

        if ($this->app->environment('testing')) {
            return self::TESTING_ONLY_SECRET;
        }

        throw new RuntimeException('hummingbird_patient_hmac_secret_unavailable');
    }
}
