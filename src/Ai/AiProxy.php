<?php

/**
 * Grafida — Joomla content editing, untethered.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Ai;

use Grafida\Http\HttpException;
use Grafida\Http\HttpResponse;
use Grafida\Http\Transport;

/**
 * CORS / non-streaming proxy for AI provider calls.
 *
 * When the SPA cannot reach an AI API directly (a provider that refuses the
 * `shell://app` origin's CORS preflight, a refused redirect), it hands the
 * request to this proxy which forwards it over PHP's
 * HTTP stack and returns the raw response body.
 *
 * Security model
 * --------------
 * Only the host name of the target URL is validated — it must equal the host of
 * the configured service endpoint so we cannot be used as an open relay.  The
 * proxy intentionally does NOT inject the API key; the SPA already populates the
 * Authorization / x-api-key header before calling us, which keeps the secret
 * injection logic on the JS side where it can be streamed natively.
 *
 * This is the non-streaming fallback.  SSE / chunked-transfer responses are NOT
 * forwarded here; streaming goes via the resolved-config endpoint (Step 6).
 */
final class AiProxy
{
    public function __construct(
        private readonly AiServiceManager $services,
        private readonly Defaults $defaults,
        private readonly Transport $http,
    ) {}

    /**
     * Validates the target URL, forwards the request, and returns the raw response.
     *
     * @param array<string, string> $headers HTTP headers supplied by the SPA (incl. auth).
     *
     * @return array{status: int, body: string}
     *
     * @throws AiProxyException When the target URL is not allowed or the request fails.
     */
    public function forward(
        int $serviceId,
        string $url,
        string $method,
        array $headers,
        string $body,
    ): array {
        $service = $this->services->find($serviceId);

        if ($service === null) {
            throw new AiProxyException('Unknown AI service #' . $serviceId, 404);
        }

        $allowedHost = $this->resolvedEndpointHost($service);

        if ($allowedHost === null || $allowedHost === '') {
            throw new AiProxyException('The AI service has no configured endpoint.', 400);
        }

        $targetHost = parse_url($url, \PHP_URL_HOST);

        if (!\is_string($targetHost) || strtolower($targetHost) !== strtolower($allowedHost)) {
            throw new AiProxyException(
                'Proxy target host "' . ($targetHost ?? '') . '" does not match the '
                . 'configured service endpoint host "' . $allowedHost . '".',
                403,
            );
        }

        try {
            $response = $this->http->request(strtoupper($method), $url, $headers, $body !== '' ? $body : null);
        } catch (HttpException $e) {
            throw new AiProxyException('AI proxy transport failure: ' . $e->getMessage(), 502);
        }

        return ['status' => $response->status, 'body' => $response->body];
    }

    /**
     * Returns the hostname of the service's resolved endpoint.
     *
     * Uses the service's own `endpoint` field if non-empty (the user may have
     * overridden the provider preset), falling back to the bundled provider
     * preset when the service endpoint is blank.
     */
    private function resolvedEndpointHost(AiService $service): ?string
    {
        $endpoint = $service->endpoint;

        if ($endpoint === '') {
            $preset   = $this->defaults->providers()[$service->provider] ?? null;
            $endpoint = is_array($preset) ? ($preset['endpoint'] ?? '') : '';
        }

        if ($endpoint === '') {
            return null;
        }

        $host = parse_url($endpoint, \PHP_URL_HOST);

        return \is_string($host) ? $host : null;
    }
}
