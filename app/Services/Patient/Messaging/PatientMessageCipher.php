<?php

namespace App\Services\Patient\Messaging;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Str;
use JsonException;
use RuntimeException;

/**
 * Dedicated, version-addressable encryption boundary for message bodies.
 *
 * Patient communication content must not silently reuse APP_KEY. The current
 * key and any retained decryption-only keys are supplied from protected
 * runtime configuration and are never stored with the ciphertext.
 */
class PatientMessageCipher
{
    /** @var array<string, Encrypter> */
    private array $encrypters = [];

    public function __construct(private readonly Application $app) {}

    public function assertAvailable(string $keyVersion): void
    {
        $this->encrypter($keyVersion);
    }

    public function encrypt(string $plaintext, string $keyVersion, string $context): string
    {
        $configuredVersion = trim((string) config(
            'hummingbird-patient.messaging.encryption_key_version',
        ));

        if ($configuredVersion === '' || ! hash_equals($configuredVersion, $keyVersion)) {
            throw new RuntimeException('patient_message_encryption_key_not_current');
        }

        try {
            $envelope = json_encode([
                'version' => 1,
                'context_digest' => $this->contextDigest($context),
                'body' => $plaintext,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (JsonException $exception) {
            throw new RuntimeException('patient_message_encryption_envelope_invalid', previous: $exception);
        }

        return $this->encrypter($keyVersion)->encryptString($envelope);
    }

    public function decrypt(string $ciphertext, string $keyVersion, string $context): string
    {
        $plaintext = $this->encrypter($keyVersion)->decryptString($ciphertext);
        try {
            $envelope = json_decode($plaintext, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('patient_message_encryption_envelope_invalid', previous: $exception);
        }

        if (! is_array($envelope)
            || ($envelope['version'] ?? null) !== 1
            || ! is_string($envelope['context_digest'] ?? null)
            || ! is_string($envelope['body'] ?? null)
            || ! hash_equals($this->contextDigest($context), $envelope['context_digest'])
        ) {
            throw new RuntimeException('patient_message_encryption_context_mismatch');
        }

        return $envelope['body'];
    }

    public function contextFor(string $threadUuid, string $messageUuid): string
    {
        if (! Str::isUuid($threadUuid) || ! Str::isUuid($messageUuid)) {
            throw new RuntimeException('patient_message_encryption_context_invalid');
        }

        return 'thread:'.Str::lower($threadUuid).'|message:'.Str::lower($messageUuid);
    }

    private function encrypter(string $keyVersion): Encrypter
    {
        if (isset($this->encrypters[$keyVersion])) {
            return $this->encrypters[$keyVersion];
        }

        $key = $this->keyForVersion($keyVersion);
        if (! Encrypter::supported($key, 'AES-256-CBC')) {
            throw new RuntimeException('patient_message_encryption_key_invalid');
        }

        return $this->encrypters[$keyVersion] = new Encrypter($key, 'AES-256-CBC');
    }

    private function keyForVersion(string $keyVersion): string
    {
        $currentVersion = trim((string) config(
            'hummingbird-patient.messaging.encryption_key_version',
        ));
        $configured = $currentVersion !== '' && hash_equals($currentVersion, $keyVersion)
            ? config('hummingbird-patient.messaging.encryption_key')
            : $this->previousKey($keyVersion);

        if (is_string($configured) && trim($configured) !== '') {
            return $this->decodeKey($configured);
        }

        if ($this->app->environment('testing') && str_starts_with($keyVersion, 'test-')) {
            return hash('sha256', 'hummingbird-patient-message-test-only-key|'.$keyVersion, true);
        }

        throw new RuntimeException('patient_message_encryption_key_unavailable');
    }

    private function previousKey(string $keyVersion): mixed
    {
        $encoded = config('hummingbird-patient.messaging.previous_encryption_keys_json');
        if (! is_string($encoded) || trim($encoded) === '') {
            return null;
        }

        try {
            $keyRing = json_decode($encoded, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('patient_message_encryption_key_ring_invalid', previous: $exception);
        }

        if (! is_array($keyRing)) {
            throw new RuntimeException('patient_message_encryption_key_ring_invalid');
        }

        return $keyRing[$keyVersion] ?? null;
    }

    private function decodeKey(string $configured): string
    {
        if (! str_starts_with($configured, 'base64:')) {
            throw new RuntimeException('patient_message_encryption_key_format_invalid');
        }

        $decoded = base64_decode(substr($configured, 7), true);
        if (! is_string($decoded) || strlen($decoded) !== 32) {
            throw new RuntimeException('patient_message_encryption_key_format_invalid');
        }

        return $decoded;
    }

    private function contextDigest(string $context): string
    {
        if ($context === '' || strlen($context) > 200 || ! Str::isAscii($context)) {
            throw new RuntimeException('patient_message_encryption_context_invalid');
        }

        return hash('sha256', "hummingbird-patient-message\0".$context);
    }
}
