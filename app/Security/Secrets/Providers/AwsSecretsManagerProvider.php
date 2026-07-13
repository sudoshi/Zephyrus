<?php

namespace App\Security\Secrets\Providers;

use App\Integrations\Healthcare\Exceptions\IntegrationCredentialException;
use App\Security\Secrets\ResolvedSecret;
use App\Security\Secrets\SecretProvider;
use App\Security\Secrets\SecretReferenceUri;
use App\Security\Secrets\SecretValueSelector;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;

final class AwsSecretsManagerProvider implements SecretProvider
{
    public function __construct(private readonly SecretValueSelector $selector) {}

    public function scheme(): string
    {
        return 'aws-secretsmanager';
    }

    public function enabled(): bool
    {
        return (bool) config('integrations.secret_providers.aws-secretsmanager.enabled', false)
            && filled(config('integrations.secret_providers.aws-secretsmanager.access_key_id'))
            && filled(config('integrations.secret_providers.aws-secretsmanager.secret_access_key'));
    }

    public function validateReference(SecretReferenceUri $reference): void
    {
        if ($reference->host === null || preg_match('/^[a-z]{2}(?:-gov)?-[a-z]+-\d$/', $reference->host) !== 1) {
            throw new IntegrationCredentialException('credential_aws_region_invalid');
        }
        $regions = config('integrations.secret_providers.aws-secretsmanager.allowed_regions', []);
        if (! is_array($regions) || ! in_array($reference->host, $regions, true)) {
            throw new IntegrationCredentialException('credential_aws_region_not_allowed');
        }
        $secretId = $reference->pathWithoutLeadingSlash();
        if ($secretId === '' || strlen($secretId) > 190 || preg_match('/^[A-Za-z0-9_+=.@\/-]+$/', $secretId) !== 1) {
            throw new IntegrationCredentialException('credential_aws_secret_id_invalid');
        }
        $this->endpoint($reference->host);
    }

    public function resolve(SecretReferenceUri $reference): ResolvedSecret
    {
        $this->validateReference($reference);
        if (! $this->enabled()) {
            throw new IntegrationCredentialException('credential_provider_not_configured');
        }

        $region = (string) $reference->host;
        $endpoint = $this->endpoint($region);
        $payload = json_encode(['SecretId' => $reference->pathWithoutLeadingSlash()], JSON_THROW_ON_ERROR);
        $headers = $this->signedHeaders($endpoint, $region, $payload);
        $response = Http::withHeaders($headers)
            ->withBody($payload, 'application/x-amz-json-1.1')
            ->connectTimeout(5)
            ->timeout((int) config('integrations.health_timeout_seconds', 10))
            ->withOptions(['allow_redirects' => false])
            ->post($endpoint);
        if ($response->redirect()) {
            throw new IntegrationCredentialException('credential_aws_redirect_rejected');
        }
        if (! $response->successful()) {
            throw new IntegrationCredentialException('credential_aws_http_'.$response->status());
        }
        $result = $response->json();
        if (! is_array($result)) {
            throw new IntegrationCredentialException('credential_aws_payload_invalid');
        }
        $value = $result['SecretString'] ?? null;
        if (! is_string($value) && is_string($result['SecretBinary'] ?? null)) {
            $decoded = base64_decode($result['SecretBinary'], true);
            $value = is_string($decoded) ? $decoded : null;
        }
        if (! is_string($value) || $value === '') {
            throw new IntegrationCredentialException('credential_aws_secret_missing');
        }

        $createdDate = isset($result['CreatedDate']) && is_numeric($result['CreatedDate'])
            ? CarbonImmutable::createFromTimestampUTC((int) $result['CreatedDate'])
            : null;

        return new ResolvedSecret(
            $this->selector->fromScalarOrJson($value, $reference->selector),
            $this->scheme(),
            isset($result['VersionId']) ? (string) $result['VersionId'] : null,
            metadata: [
                'createdAtIso' => $createdDate?->toIso8601String(),
                'versionStageCurrent' => in_array('AWSCURRENT', (array) ($result['VersionStages'] ?? []), true),
            ],
        );
    }

    /** @return array<string, string> */
    private function signedHeaders(string $endpoint, string $region, string $payload): array
    {
        $accessKey = (string) config('integrations.secret_providers.aws-secretsmanager.access_key_id');
        $secretKey = (string) config('integrations.secret_providers.aws-secretsmanager.secret_access_key');
        $sessionToken = (string) config('integrations.secret_providers.aws-secretsmanager.session_token');
        $host = (string) parse_url($endpoint, PHP_URL_HOST);
        $amzDate = gmdate('Ymd\THis\Z');
        $date = substr($amzDate, 0, 8);
        $headers = [
            'content-type' => 'application/x-amz-json-1.1',
            'host' => $host,
            'x-amz-date' => $amzDate,
            'x-amz-target' => 'secretsmanager.GetSecretValue',
        ];
        if ($sessionToken !== '') {
            $headers['x-amz-security-token'] = $sessionToken;
        }
        ksort($headers);
        $canonicalHeaders = collect($headers)
            ->map(fn (string $value, string $key): string => strtolower($key).':'.trim(preg_replace('/\s+/', ' ', $value) ?? $value)."\n")
            ->implode('');
        $signedHeaders = implode(';', array_keys($headers));
        $canonicalRequest = implode("\n", [
            'POST',
            '/',
            '',
            $canonicalHeaders,
            $signedHeaders,
            hash('sha256', $payload),
        ]);
        $scope = "{$date}/{$region}/secretsmanager/aws4_request";
        $stringToSign = implode("\n", [
            'AWS4-HMAC-SHA256',
            $amzDate,
            $scope,
            hash('sha256', $canonicalRequest),
        ]);
        $dateKey = hash_hmac('sha256', $date, 'AWS4'.$secretKey, true);
        $regionKey = hash_hmac('sha256', $region, $dateKey, true);
        $serviceKey = hash_hmac('sha256', 'secretsmanager', $regionKey, true);
        $signingKey = hash_hmac('sha256', 'aws4_request', $serviceKey, true);
        $signature = hash_hmac('sha256', $stringToSign, $signingKey);

        $result = [
            'Authorization' => "AWS4-HMAC-SHA256 Credential={$accessKey}/{$scope}, SignedHeaders={$signedHeaders}, Signature={$signature}",
            'Content-Type' => $headers['content-type'],
            'Host' => $headers['host'],
            'X-Amz-Date' => $headers['x-amz-date'],
            'X-Amz-Target' => $headers['x-amz-target'],
        ];
        if ($sessionToken !== '') {
            $result['X-Amz-Security-Token'] = $sessionToken;
        }

        return $result;
    }

    private function endpoint(string $region): string
    {
        $template = (string) config(
            'integrations.secret_providers.aws-secretsmanager.endpoint_template',
            'https://secretsmanager.%s.amazonaws.com',
        );
        if (substr_count($template, '%s') !== 1) {
            throw new IntegrationCredentialException('credential_aws_endpoint_template_invalid');
        }
        $url = sprintf($template, $region);
        $parts = parse_url($url);
        if (! is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || ! filled($parts['host'] ?? null)
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
            || ($parts['path'] ?? '') !== '') {
            throw new IntegrationCredentialException('credential_aws_endpoint_template_invalid');
        }

        return $url;
    }
}
