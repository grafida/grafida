<?php

/**
 * Grafida — Joomla content editing, untethered.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Http;

use Grafida\Shell\Request;

/**
 * Everything a route handler needs: the path parameters {@see Router}
 * matched out of the URL (e.g. `siteId`, `articleId`, `key`), the parsed
 * request body, and the raw request itself (handlers read the query string
 * off it via `$context->request()->query(...)`).
 */
final class RouteContext
{
    /**
     * @param array<string, string> $params matched path parameters, keyed by placeholder name
     * @param array<string, mixed>  $body   the parsed JSON request body
     */
    public function __construct(
        private readonly array $params,
        private readonly array $body,
        private readonly Request $request,
    ) {}

    /** A path parameter as an int (0 when absent — every registered placeholder is required, so this only happens for a typo'd name). */
    public function int(string $name): int
    {
        $value = $this->params[$name] ?? null;

        return $value !== null ? (int) $value : 0;
    }

    /** A path parameter as a string ('' when absent). */
    public function string(string $name): string
    {
        return $this->params[$name] ?? '';
    }

    /** @return array<string, mixed> the parsed JSON request body */
    public function body(): array
    {
        return $this->body;
    }

    public function request(): Request
    {
        return $this->request;
    }
}
