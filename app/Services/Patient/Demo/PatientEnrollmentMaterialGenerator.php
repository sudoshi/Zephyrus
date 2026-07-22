<?php

namespace App\Services\Patient\Demo;

use RuntimeException;

class PatientEnrollmentMaterialGenerator
{
    /** @return array{challenge_token: string, verification_code: string} */
    public function generate(string $platform): array
    {
        if (! in_array($platform, ['ios', 'android'], true)) {
            throw new RuntimeException('reference_patient_enrollment_platform_invalid');
        }

        return [
            'challenge_token' => $this->base64Url(random_bytes(48)),
            'verification_code' => str_pad((string) random_int(0, 99_999_999), 8, '0', STR_PAD_LEFT),
        ];
    }

    private function base64Url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
