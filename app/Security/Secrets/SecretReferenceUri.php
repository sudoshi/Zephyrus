<?php

namespace App\Security\Secrets;

use App\Integrations\Healthcare\Exceptions\IntegrationCredentialException;

final readonly class SecretReferenceUri
{
    private function __construct(
        public string $raw,
        public string $scheme,
        public ?string $host,
        public string $path,
        public ?string $selector,
    ) {}

    public static function parse(string $reference): self
    {
        if ($reference === '' || strlen($reference) > 255 || preg_match('/[\x00-\x1F\x7F]/', $reference) === 1) {
            throw new IntegrationCredentialException('credential_reference_invalid');
        }

        $parts = parse_url($reference);
        if (! is_array($parts)
            || ! isset($parts['scheme'])
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])) {
            throw new IntegrationCredentialException('credential_reference_invalid');
        }

        $scheme = strtolower((string) $parts['scheme']);
        if (preg_match('/^[a-z][a-z0-9+.-]{1,39}$/', $scheme) !== 1) {
            throw new IntegrationCredentialException('credential_reference_invalid');
        }

        $path = rawurldecode((string) ($parts['path'] ?? ''));
        if ($path === ''
            || str_contains($path, "\0")
            || preg_match('#(?:^|/)\.\.?(/|$)#', $path) === 1) {
            throw new IntegrationCredentialException('credential_reference_invalid');
        }

        $host = isset($parts['host']) ? strtolower(rtrim((string) $parts['host'], '.')) : null;
        $selector = isset($parts['fragment']) ? rawurldecode((string) $parts['fragment']) : null;
        if ($selector !== null && preg_match('/^[A-Za-z0-9_.-]{1,120}$/', $selector) !== 1) {
            throw new IntegrationCredentialException('credential_reference_selector_invalid');
        }

        return new self($reference, $scheme, $host, $path, $selector);
    }

    public function referenceFingerprint(): string
    {
        return hash('sha256', $this->raw);
    }

    public function pathWithoutLeadingSlash(): string
    {
        return ltrim($this->path, '/');
    }
}
