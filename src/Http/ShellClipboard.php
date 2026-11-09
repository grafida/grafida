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
 * The clipboard text the native shell read for a request (grafida/grafida#85).
 *
 * The shell owns the window, so it reads the OS clipboard itself (GTK on Linux), with no helper
 * tool and no prompt, which PHP cannot: on Linux `ClipboardService` needs `wl-paste`, `xclip` or
 * `xsel`, and a desktop may have none of them. The SPA asks with `X-Shell-Clipboard: read`; the shell
 * puts the text in the request **body** and forwards `X-Shell-Clipboard: text`. Under Boson the
 * header never arrives ({@see \Grafida\Application\BosonBridge} drops it), so PHP reads the
 * clipboard as before.
 */
final class ShellClipboard
{
    public const string HEADER = 'x-shell-clipboard';

    private function __construct()
    {
    }

    /** The text the shell read (possibly empty), or null when the shell did not read it. */
    public static function text(Request $request): ?string
    {
        return $request->header(self::HEADER) === 'text' ? $request->body : null;
    }
}
