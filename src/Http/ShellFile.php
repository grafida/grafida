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
 * A file or folder the user picked in one of the native shell's dialogs (grafida/grafida#85).
 *
 * Under the shell, a dialog hands the page an opaque **token**, never a path. The page sends the
 * token back as `X-Shell-File`; the shell drops any `X-Shell-File-Path` the page sent, resolves the
 * token, and forwards the real path in `X-Shell-File-Path`. So a request carrying that header names
 * exactly what the user picked this session, and nothing script in the page made up.
 *
 * ⚠️ That guarantee is the shell's: it strips any `X-Shell-File-Path` the page sends. Nothing else
 * may ever forward a request to the worker without doing the same, or any script in the page could
 * name any file on the disk.
 */
final class ShellFile
{
    public const string PATH_HEADER = 'x-shell-file-path';

    public const string TOKEN_HEADER = 'x-shell-file';

    private function __construct()
    {
    }

    /** The path the shell resolved from a dialog token, or null when the request carries none. */
    public static function path(Request $request): ?string
    {
        $path = $request->header(self::PATH_HEADER);

        return $path === null || $path === '' ? null : $path;
    }

    /**
     * Where an export goes: the folder the user picked in the shell's dialog, else the `directory`
     * the SPA sent in the body (a caller with no shell: the tests, a plain browser).
     *
     * @param array<string, mixed> $body
     */
    public static function directory(Request $request, array $body): string
    {
        $directory = $body['directory'] ?? '';

        return self::path($request) ?? (\is_string($directory) ? $directory : '');
    }
}
