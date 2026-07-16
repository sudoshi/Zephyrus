<?php

namespace App\Services\Auth\Oidc;

use App\Security\Network\OidcUrlPolicy;
use App\Security\Network\UnsafeOidcUrl;
use App\Services\Auth\Oidc\Exceptions\OidcException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use JsonException;

class OidcHttpClient
{
    public function __construct(private readonly OidcUrlPolicy $urlPolicy) {}

    /** @return array<string, mixed> */
    public function getJson(string $url, string $accept, string $reasonPrefix): array
    {
        $this->assertSafe($url);

        try {
            $response = Http::accept($accept)
                ->connectTimeout((int) config('auth-drivers.oidc_network.connect_timeout_seconds', 3))
                ->timeout((int) config('auth-drivers.oidc_network.timeout_seconds', 8))
                ->withOptions(['allow_redirects' => false, 'stream' => true])
                ->get($url);
        } catch (ConnectionException $exception) {
            throw new OidcException($reasonPrefix.'_unreachable', previous: $exception);
        }

        return $this->decode($response, $reasonPrefix);
    }

    /**
     * @param  array<string, scalar>  $form
     * @return array<string, mixed>
     */
    public function postFormJson(string $url, array $form, string $reasonPrefix): array
    {
        $this->assertSafe($url);

        try {
            $response = Http::asForm()
                ->acceptJson()
                ->connectTimeout((int) config('auth-drivers.oidc_network.connect_timeout_seconds', 3))
                ->timeout((int) config('auth-drivers.oidc_network.timeout_seconds', 8))
                ->withOptions(['allow_redirects' => false, 'stream' => true])
                ->post($url, $form);
        } catch (ConnectionException $exception) {
            throw new OidcException($reasonPrefix.'_unreachable', previous: $exception);
        }

        return $this->decode($response, $reasonPrefix);
    }

    /** @return array<string, mixed> */
    private function decode(Response $response, string $reasonPrefix): array
    {
        if ($response->redirect()) {
            throw new OidcException($reasonPrefix.'_redirect_rejected');
        }
        if (! $response->successful()) {
            throw new OidcException($reasonPrefix.'_http_'.$response->status());
        }

        $maximum = (int) config('auth-drivers.oidc_network.max_response_bytes', 1_048_576);
        $contentLength = $response->header('Content-Length');
        if (is_string($contentLength) && ctype_digit($contentLength) && (int) $contentLength > $maximum) {
            throw new OidcException($reasonPrefix.'_response_too_large');
        }

        $stream = $response->toPsrResponse()->getBody();
        if ($stream->isSeekable()) {
            $stream->rewind();
        }
        $body = '';
        while (! $stream->eof()) {
            $chunk = $stream->read(min(8192, $maximum + 1 - strlen($body)));
            if ($chunk === '') {
                break;
            }
            $body .= $chunk;
            if (strlen($body) > $maximum) {
                throw new OidcException($reasonPrefix.'_response_too_large');
            }
        }
        if (! $stream->eof()) {
            throw new OidcException($reasonPrefix.'_response_too_large');
        }

        try {
            $decoded = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new OidcException($reasonPrefix.'_invalid_json', previous: $exception);
        }
        if (! is_array($decoded)) {
            throw new OidcException($reasonPrefix.'_invalid_json');
        }

        return $decoded;
    }

    private function assertSafe(string $url): void
    {
        try {
            $this->urlPolicy->assertSafeOutboundUrl($url);
        } catch (UnsafeOidcUrl $exception) {
            throw new OidcException($exception->reason, previous: $exception);
        }
    }
}
