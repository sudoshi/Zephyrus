<?php

namespace App\Integrations\Healthcare\Services;

use App\Integrations\Healthcare\Exceptions\IntegrationCredentialException;
use Carbon\CarbonImmutable;

final class CertificateInspector
{
    /**
     * INT-SECRET — the presented-peer fingerprints a pin policy compares against.
     * Computes the leaf certificate SHA-256, the SubjectPublicKeyInfo (SPKI)
     * SHA-256, the issuer distinguished name, subject DN, and SANs from a
     * presented server-peer PEM. Throws on an unreadable certificate so a
     * required pin fails closed rather than silently passing.
     *
     * @return array{certSha256:string, spkiSha256:string, issuerDn:?string, subjectDn:?string, subjectAltNames:list<string>}
     */
    public function peerFingerprints(string $peerCertificatePem): array
    {
        $certificate = @openssl_x509_read($peerCertificatePem);
        $parsed = $certificate !== false ? openssl_x509_parse($certificate, false) : false;
        if ($certificate === false || ! is_array($parsed)) {
            throw new IntegrationCredentialException('peer_certificate_invalid');
        }
        $publicKey = @openssl_pkey_get_public($certificate);
        $details = $publicKey !== false ? openssl_pkey_get_details($publicKey) : false;
        if ($details === false || ! isset($details['key']) || ! is_string($details['key'])) {
            throw new IntegrationCredentialException('peer_public_key_unreadable');
        }
        $spkiDer = $this->pemBodyToDer((string) $details['key']);
        if ($spkiDer === null) {
            throw new IntegrationCredentialException('peer_public_key_unreadable');
        }
        $sans = [];
        $rawSan = data_get($parsed, 'extensions.subjectAltName');
        if (is_string($rawSan)) {
            foreach (explode(',', $rawSan) as $entry) {
                $entry = trim($entry);
                if ($entry !== '') {
                    $sans[] = strtolower($entry);
                }
            }
        }

        return [
            'certSha256' => strtolower((string) openssl_x509_fingerprint($certificate, 'sha256')),
            'spkiSha256' => hash('sha256', $spkiDer),
            'issuerDn' => $this->distinguishedName($parsed['issuer'] ?? []),
            'subjectDn' => $this->distinguishedName($parsed['subject'] ?? []),
            'subjectAltNames' => $sans,
        ];
    }

    private function pemBodyToDer(string $pem): ?string
    {
        if (! preg_match('/-----BEGIN [^-]+-----(.*?)-----END [^-]+-----/s', $pem, $matches)) {
            return null;
        }
        $decoded = base64_decode(preg_replace('/\s+/', '', $matches[1]) ?? '', true);

        return $decoded === false || $decoded === '' ? null : $decoded;
    }

    public function matchesPrivateKey(string $certificatePem, string $privateKeyPem): bool
    {
        $certificate = @openssl_x509_read($certificatePem);
        $privateKey = @openssl_pkey_get_private($privateKeyPem);
        if ($certificate === false || $privateKey === false) {
            return false;
        }

        return openssl_x509_check_private_key($certificate, $privateKey);
    }

    /** @return array<string, mixed> */
    public function inspect(string $pemBundle, CarbonImmutable $evaluatedFor): array
    {
        preg_match_all(
            '/-----BEGIN CERTIFICATE-----.*?-----END CERTIFICATE-----/s',
            $pemBundle,
            $matches,
        );
        $certificates = $matches[0] ?? [];
        if ($certificates === []) {
            throw new IntegrationCredentialException('credential_certificate_pem_missing');
        }

        $chain = [];
        foreach ($certificates as $index => $pem) {
            $certificate = openssl_x509_read($pem);
            $parsed = $certificate !== false ? openssl_x509_parse($certificate, false) : false;
            if ($certificate === false || ! is_array($parsed)) {
                throw new IntegrationCredentialException('credential_certificate_invalid');
            }
            $notBefore = isset($parsed['validFrom_time_t'])
                ? CarbonImmutable::createFromTimestampUTC((int) $parsed['validFrom_time_t'])
                : null;
            $notAfter = isset($parsed['validTo_time_t'])
                ? CarbonImmutable::createFromTimestampUTC((int) $parsed['validTo_time_t'])
                : null;
            if ($index === 0 && ($notBefore === null || $notAfter === null)) {
                throw new IntegrationCredentialException('credential_certificate_validity_missing');
            }
            if ($index === 0 && $notBefore->greaterThan($evaluatedFor)) {
                throw new IntegrationCredentialException('credential_certificate_not_yet_valid');
            }
            if ($index === 0 && $notAfter->lessThanOrEqualTo($evaluatedFor)) {
                throw new IntegrationCredentialException('credential_certificate_expired');
            }

            $chain[] = [
                'position' => $index,
                'subject' => $this->distinguishedName($parsed['subject'] ?? []),
                'issuer' => $this->distinguishedName($parsed['issuer'] ?? []),
                'serialHex' => isset($parsed['serialNumberHex']) ? strtoupper((string) $parsed['serialNumberHex']) : null,
                'fingerprintSha256' => strtolower((string) openssl_x509_fingerprint($certificate, 'sha256')),
                'notBeforeIso' => $notBefore?->toIso8601String(),
                'notAfterIso' => $notAfter?->toIso8601String(),
                'subjectAlternativeName' => data_get($parsed, 'extensions.subjectAltName'),
                'keyUsage' => data_get($parsed, 'extensions.keyUsage'),
                'extendedKeyUsage' => data_get($parsed, 'extensions.extendedKeyUsage'),
                'signatureType' => isset($parsed['signatureTypeSN']) ? (string) $parsed['signatureTypeSN'] : null,
            ];
        }

        return [
            'chainLength' => count($chain),
            'leaf' => $chain[0],
            'chain' => $chain,
        ];
    }

    private function distinguishedName(mixed $parts): ?string
    {
        if (! is_array($parts) || $parts === []) {
            return null;
        }
        $allowed = collect($parts)
            ->only(['CN', 'O', 'OU', 'C', 'ST', 'L'])
            ->map(fn (mixed $value, string $key): string => $key.'='.str_replace(["\r", "\n", ','], ' ', (string) $value))
            ->values()
            ->all();

        return $allowed !== [] ? implode(', ', $allowed) : null;
    }
}
