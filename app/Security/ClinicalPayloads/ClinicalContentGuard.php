<?php

namespace App\Security\ClinicalPayloads;

use BackedEnum;
use DateTimeInterface;
use ReflectionObject;
use Stringable;
use Throwable;
use UnitEnum;

/**
 * Fail-closed tripwire for output channels that must never carry clinical
 * bodies or credentials. This is deliberately not a de-identification tool:
 * payload storage remains the primary authority and callers must still emit
 * allowlisted diagnostics. The signatures below catch structured healthcare
 * formats, secrets, and test canaries if either contract is accidentally
 * bypassed.
 */
final class ClinicalContentGuard
{
    public const REDACTED = '[clinical-content-redacted]';

    private const MAX_DEPTH = 10;

    private const SAFE_OPAQUE_KEYS = [
        'active_api_token_count',
        'api_tokens_revoked',
        'database_sessions_revoked',
        'message_id',
        'must_change_password',
        'payload_kind',
        'payload_object_id',
        'payload_quarantine_id',
        'payload_sha256',
        'payload_uuid',
        'resource_type',
    ];

    private const QUEUE_FRAMEWORK_PROPERTIES = [
        'afterCommit',
        'chainCatchCallbacks',
        'chainConnection',
        'chainQueue',
        'chained',
        'connection',
        'deduplicator',
        'delay',
        'failOnTimeout',
        'job',
        'maxExceptions',
        'messageGroup',
        'middleware',
        'queue',
        'timeout',
        'tries',
    ];

    /** @var list<string> */
    private const CONTENT_PATTERNS = [
        // Reserved non-production canary used by the release and boundary suites.
        '/\bZPHI[-_][A-Z0-9._-]+\b/i',

        // HL7 v2, X12, CDA/C-CDA, NCPDP SCRIPT, and DICOM/DICOMweb bodies.
        '/\b(?:MSH|PID|PV1|OBX|ORC|RXA)\|/i',
        '/\bISA[^A-Za-z0-9\r\n].{20,}/i',
        '/<\s*(?:[A-Za-z][\w.-]*:)?ClinicalDocument\b/i',
        '/urn:hl7-org:v3/i',
        '/<\s*(?:NewRx|RxChange|RxRenewal|MedicationPrescribed)\b/i',
        '/<\s*(?:Patient|Encounter|Observation|Condition|Procedure|DiagnosticReport|MedicationRequest|Coverage|Claim)\b[^>]*\bxmlns\s*=\s*["\']http:\/\/hl7\.org\/fhir["\']/i',
        '/(?:\x00{8,}.{0,256})?DICM/s',
        '/\b(?:0010[,|]00(?:10|20)|00100010|00100020|PatientName|PatientID)\b/i',

        // Raw FHIR/vendor JSON or CSV-like clinical fields.
        '/"(?:raw_hl7|patient|patient_name|patientName|mrn|medical_record_number|clinical_document|resource_data)"\s*:/i',
        '/(?:^|[,;\t])\s*(?:mrn|patient(?:_?name|_?id))\s*(?:[,;\t]|$)/im',

        // Authentication material and private keys.
        '/\b(?:Authorization\s*:\s*)?(?:Bearer|Basic)\s+[A-Za-z0-9._~+\/=:-]{8,}/i',
        '/\b(?:Cookie|Set-Cookie)\s*:\s*[^\r\n]{8,}/i',
        '/\b(?:api[_-]?key|client[_-]?secret|access[_-]?token|refresh[_-]?token|password)\s*[:=]\s*["\']?[A-Za-z0-9._~+\/=:-]{8,}/i',
        '/\beyJ[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}\b/',
        '/-----BEGIN(?: [A-Z0-9]+)? PRIVATE KEY-----/i',
    ];

    public function assertSafe(mixed $value, string $errorCode = 'clinical_content_output_rejected'): void
    {
        if ($this->contains($value) || $this->encodedCompositeContains($value)) {
            throw new ClinicalPayloadException($errorCode);
        }
    }

    public function contains(mixed $value, ?string $key = null, int $depth = 0): bool
    {
        if ($depth > self::MAX_DEPTH) {
            return false;
        }

        if ($key !== null && $this->sensitiveKey($key)) {
            return $value !== null && $value !== '' && $value !== [];
        }

        if ($value === null || is_bool($value) || is_int($value) || is_float($value)) {
            return false;
        }

        if (is_string($value)) {
            return $this->stringContains($value);
        }

        if ($value instanceof Throwable) {
            return $this->stringContains($value->getMessage())
                || ($value->getPrevious() !== null && $this->contains($value->getPrevious(), depth: $depth + 1));
        }

        if ($value instanceof BackedEnum) {
            return $this->contains($value->value, depth: $depth + 1);
        }

        if ($value instanceof UnitEnum || $value instanceof DateTimeInterface) {
            return false;
        }

        if ($value instanceof Stringable) {
            return $this->stringContains((string) $value);
        }

        if (is_object($value)) {
            return $this->contains($this->objectProperties($value), depth: $depth + 1);
        }

        if (! is_array($value)) {
            return true;
        }

        foreach ($value as $nestedKey => $nested) {
            if ($this->contains($nested, is_string($nestedKey) ? $nestedKey : null, $depth + 1)) {
                return true;
            }
        }

        return false;
    }

    public function redact(mixed $value, ?string $key = null, int $depth = 0): mixed
    {
        if ($depth > self::MAX_DEPTH || ($key !== null && $this->sensitiveKey($key))) {
            return self::REDACTED;
        }

        if ($value === null || is_bool($value) || is_int($value) || is_float($value)) {
            return $value;
        }

        if (is_string($value)) {
            return $this->stringContains($value) ? self::REDACTED : $value;
        }

        if ($value instanceof Throwable) {
            return [
                'class' => $value::class,
                'message' => $this->stringContains($value->getMessage()) ? self::REDACTED : $value->getMessage(),
                'frames' => array_map(
                    static fn (array $frame): array => array_filter([
                        'file' => isset($frame['file']) ? basename((string) $frame['file']) : null,
                        'line' => isset($frame['line']) ? (int) $frame['line'] : null,
                        'class' => $frame['class'] ?? null,
                        'function' => $frame['function'] ?? null,
                    ], static fn (mixed $item): bool => $item !== null),
                    array_slice($value->getTrace(), 0, 25),
                ),
            ];
        }

        if ($value instanceof BackedEnum) {
            return $this->redact($value->value, depth: $depth + 1);
        }

        if ($value instanceof UnitEnum) {
            return $value->name;
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }

        if ($value instanceof Stringable) {
            return $this->redact((string) $value, depth: $depth + 1);
        }

        if (is_object($value)) {
            return ['class' => $value::class];
        }

        if (! is_array($value)) {
            return self::REDACTED;
        }

        $safe = [];
        foreach ($value as $nestedKey => $nested) {
            $safe[$nestedKey] = $this->redact(
                $nested,
                is_string($nestedKey) ? $nestedKey : null,
                $depth + 1,
            );
        }

        return $safe;
    }

    public function redactString(string $value): string
    {
        return $this->stringContains($value) ? self::REDACTED.PHP_EOL : $value;
    }

    public function assertQueueJob(ClinicalPayloadSafeQueueJob $job): void
    {
        $arguments = $job->clinicalPayloadSafeArguments();
        $this->assertSafe($arguments, 'clinical_payload_queue_payload_rejected');

        $allowed = array_fill_keys(array_merge(array_keys($arguments), self::QUEUE_FRAMEWORK_PROPERTIES), true);
        $properties = $this->objectProperties($job);
        foreach ($properties as $name => $value) {
            if (! isset($allowed[$name])) {
                throw new ClinicalPayloadException('clinical_payload_queue_contract_invalid');
            }
            if (array_key_exists($name, $arguments) && $arguments[$name] !== $value) {
                throw new ClinicalPayloadException('clinical_payload_queue_contract_invalid');
            }
        }

        $this->assertSafe($properties, 'clinical_payload_queue_payload_rejected');
        if ($this->stringContains(serialize($job))) {
            throw new ClinicalPayloadException('clinical_payload_queue_payload_rejected');
        }
    }

    private function sensitiveKey(string $key): bool
    {
        $key = trim(str_replace("\0", '', $key));
        if (in_array($key, self::SAFE_OPAQUE_KEYS, true)) {
            return false;
        }

        return preg_match(
            '/(?:^|[_-])(?:authorization|cookie|set_cookie|access_token|refresh_token|id_token|secret|password|private_key|raw|raw_hl7|payload|body|patient|mrn|resource|document|binary|attachment|clinical_text)(?:$|[_-])/i',
            $key,
        ) === 1;
    }

    private function stringContains(string $value): bool
    {
        $normalized = str_replace('\\"', '"', $value);
        if ($this->looksLikeClinicalFhir($normalized)) {
            return true;
        }
        foreach (self::CONTENT_PATTERNS as $pattern) {
            if (preg_match($pattern, $value) === 1 || ($normalized !== $value && preg_match($pattern, $normalized) === 1)) {
                return true;
            }
        }

        return false;
    }

    private function looksLikeClinicalFhir(string $value): bool
    {
        return preg_match(
            '/"resourceType"\s*:\s*"(?:Patient|Encounter|Observation|Condition|Procedure|DiagnosticReport|MedicationRequest|Coverage|Claim)"/i',
            $value,
        ) === 1 && preg_match(
            '/"(?:identifier|name|subject|patient|telecom|address|birthDate|contained|text|code|valueString|note)"\s*:/i',
            $value,
        ) === 1;
    }

    private function encodedCompositeContains(mixed $value): bool
    {
        if (! is_array($value) && ! is_object($value)) {
            return false;
        }

        try {
            return $this->stringContains(json_encode($value, JSON_THROW_ON_ERROR));
        } catch (Throwable) {
            return true;
        }
    }

    /** @return array<string, mixed> */
    private function objectProperties(object $object): array
    {
        $properties = [];
        foreach ((new ReflectionObject($object))->getProperties() as $property) {
            if ($property->isStatic() || ! $property->isInitialized($object)) {
                continue;
            }
            $properties[$property->getName()] = $property->getValue($object);
        }

        return $properties;
    }
}
