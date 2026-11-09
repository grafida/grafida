<?php

/**
 * Grafida — Joomla content editing, untethered.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Application;

use Boson\Component\Http\Response as BosonResponse;
use Boson\Contracts\Http\RequestInterface as BosonRequest;
use Boson\Contracts\Http\ResponseInterface as BosonResponseInterface;
use Grafida\Http\ShellClipboard;
use Grafida\Http\ShellFile;
use Grafida\Shell\Handler;
use Grafida\Shell\Headers;
use Grafida\Shell\Request;
use Grafida\Shell\Response;

/**
 * The adapter between Boson's HTTP types and shell-php's, so the app ships on Boson while
 * everything behind `index.php` speaks only `Grafida\Shell\Request`/`Response` (grafida/grafida#84).
 *
 * Deleted at the switch-over to the native shell, with `BosonApplication` and `EventLoopThrottle`.
 *
 * Two details keep the shipped app unchanged:
 *
 * - **The URL is Boson's re-serialisation**, `(string) $request->url`. Boson keeps the raw URL
 *   private and hands out a parsed `Uri`, which drops empty path segments and re-encodes the query.
 *   The kernel has only ever seen that parsed form, so passing its string on keeps routing, query
 *   decoding and static lookups exactly as they were.
 * - **A request header our `Headers` refuses is dropped, not fatal.** Boson validated nothing a
 *   webview sent; shell-php refuses names that are not tokens and values with CR/LF/NUL. One odd
 *   header must not turn a working request into a 500.
 *
 * And some are dropped on purpose: `X-Shell-File-Path`, `X-Shell-File` and `X-Shell-Clipboard`.
 * Under the native shell they can only come from the shell; under Boson they could only come from
 * the page (ShellFile, ShellClipboard).
 */
final class BosonBridge
{
    private function __construct()
    {
    }

    /** Boson's `SchemeRequestReceived` request → the request the handler expects. */
    public static function request(BosonRequest $request): Request
    {
        return new Request(
            (string) $request->method,
            (string) $request->url,
            self::headers($request->headers),
            $request->body,
        );
    }

    /** The handler's response → the one Boson hands to the webview. */
    public static function response(Response $response): BosonResponseInterface
    {
        $headers = [];

        // Repeated headers go over as arrays; Boson's map keeps them, though saucer then sends
        // only one value per name to the webview (a std::map; ADR 0001). The shell fixes that.
        foreach ($response->headers->toArray() as $name => $values) {
            $name = (string) $name;

            if ($name !== '') {
                $headers[$name] = $values;
            }
        }

        return new BosonResponse($response->body, $response->status, $headers);
    }

    /** Answers a Boson request with a shell-php handler. */
    public static function handle(Handler $handler, BosonRequest $request): BosonResponseInterface
    {
        return self::response($handler(self::request($request)));
    }

    /** @param iterable<mixed, mixed> $pairs one name => value pair per value, as Boson iterates */
    private static function headers(iterable $pairs): Headers
    {
        $headers = new Headers();

        foreach ($pairs as $name => $value) {
            if (!\is_string($name) || !\is_string($value)) {
                continue;
            }

            // Only the native shell may name a picked file: it strips whatever the page sent and
            // forwards the path it resolved from a dialog token. Boson has no such guard, so a page
            // sending the header itself must never reach the kernel, or any script in the page could
            // read any file on the disk (ShellFile).
            if (\in_array(strtolower($name), [ShellFile::PATH_HEADER, ShellFile::TOKEN_HEADER, ShellClipboard::HEADER], true)) {
                continue;
            }

            try {
                $headers = $headers->withAdded($name, $value);
            } catch (\InvalidArgumentException) {
                // Not a header shell-php can carry; see the class comment.
            }
        }

        return $headers;
    }
}
