<?php

/**
 * Grafida — Joomla content editing, untethered.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Application;

use Grafida\Shell\Response;
use Grafida\Shell\StaticFiles;
use Grafida\Shell\Request;
use Grafida\Http\ApiController;

/**
 * Request dispatcher: routes incoming `boson://` requests to the JSON API
 * controller, a static asset, or the single-page-app shell. A pure
 * `Request → Response` function over shell-php's types, so it is the same
 * in the stdio worker and in the tests, which need no process at all. Every
 * dependency is wired by the DI container's service providers (see
 * `Grafida\Application\Provider\*`).
 */
final class Kernel
{
    public function __construct(
        private readonly StaticFiles $static,
        private readonly ApiController $api,
    ) {}

    public function handle(Request $request): Response
    {
        $path = $request->path();

        if (str_starts_with($path, '/api/')) {
            return $this->api->dispatch($request);
        }

        $static = $this->static->find($request);

        if ($static !== null) {
            return $static;
        }

        // Single-page-app shell for any other route.
        $shell = @file_get_contents(\dirname(__DIR__, 2) . '/assets/private/view/index.html');

        return new Response((string) $shell, 200, ['Content-Type' => 'text/html; charset=utf-8']);
    }
}
