<?php

/**
 * Grafida — Joomla content editing, untethered.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Tests\Unit\Support;

use Grafida\Http\HttpException;
use Grafida\Http\HttpResponse;
use Grafida\Http\Transport;

/**
 * In-memory HTTP transport for tests. Responses are queued by an exact URL, or
 * a default response is returned. Records every request for assertions.
 */
final class FakeTransport implements Transport
{
    /** @var array<string, HttpResponse> */
    private array $byUrl = [];

    /** @var array<string, list<HttpResponse>> URL => responses still to give, in order. */
    private array $sequences = [];

    /** @var array<string, int> URL => cURL errno (0 when unspecified). */
    private array $throwForUrls = [];

    /** cURL errno every request fails with, or null when only $throwForUrls applies. */
    private ?int $throwForEverything = null;

    /** @var list<array{method: string, url: string, headers: array<string,string>, body: ?string}> */
    public array $requests = [];

    public function __construct(
        private readonly HttpResponse $default = new HttpResponse(404, ''),
    ) {}

    public function on(string $url, HttpResponse $response): self
    {
        $this->byUrl[$url] = $response;

        return $this;
    }

    /**
     * Answers successive requests to $url with $responses in order; once they run out, the last
     * one keeps answering. For a client that retries: the first answer is the failure, the next
     * the outcome of the retry.
     */
    public function onSequence(string $url, HttpResponse ...$responses): self
    {
        $this->sequences[$url] = array_values($responses);

        return $this;
    }

    /**
     * Makes a request to $url throw an {@see HttpException} instead of
     * returning a response.
     *
     * @param int $curlErrno The cURL error number to report on the thrown
     *                       exception. Defaults to 0 (not a connectivity
     *                       failure); pass a connectivity errno (e.g. 6 for
     *                       CURLE_COULDNT_RESOLVE_HOST) to simulate an offline
     *                       machine or unreachable site (gh-29).
     */
    public function throwFor(string $url, int $curlErrno = 0): self
    {
        $this->throwForUrls[$url] = $curlErrno;

        return $this;
    }

    /**
     * Makes *every* request throw — the machine is offline, rather than one
     * particular URL being unreachable. Use this when the route under test may
     * call out more than once and the point is that none of them can succeed.
     *
     * @param int $curlErrno See {@see throwFor()}.
     */
    public function throwForAll(int $curlErrno = 0): self
    {
        $this->throwForEverything = $curlErrno;

        return $this;
    }

    public function request(string $method, string $url, array $headers = [], ?string $body = null): HttpResponse
    {
        $this->requests[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body];

        if ($this->throwForEverything !== null) {
            throw new HttpException('Simulated offline machine for ' . $url, $this->throwForEverything);
        }

        if (array_key_exists($url, $this->throwForUrls)) {
            throw new HttpException('Simulated transport failure for ' . $url, $this->throwForUrls[$url]);
        }

        if (isset($this->sequences[$url]) && $this->sequences[$url] !== []) {
            return count($this->sequences[$url]) > 1
                ? array_shift($this->sequences[$url])
                : $this->sequences[$url][0];
        }

        return $this->byUrl[$url] ?? $this->default;
    }
}
