<?php

/**
 * Grafida — Joomla content editing, untethered.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Startup;

use Joomla\Database\Exception\ConnectionFailureException;

/**
 * Says, in words, why the worker could not start (gh-92).
 *
 * The native shell owns every failure that happens before PHP runs (a too-old macOS, a missing
 * WebKitGTK or WebView2) and reports it itself. What is left for us is the worker dying while it
 * builds the app: most often a database it cannot open (a data folder that is not writable, a full
 * disk). `bin/worker.php` writes this text to stderr ahead of the stack trace, and the shell's alert
 * is the tail of the worker's stderr, so it opens with something a person can act on.
 *
 * ⚠️ The text is **hard-coded English**: it runs before the language files are loaded, or instead
 * of them.
 */
final class FailureReporter
{
    /** The worker's exit status when it could not start: EX_UNAVAILABLE, as before. */
    public const EXIT_FAILED = 69;

    /**
     * Turn a start-up failure into something a person can act on.
     *
     * Pure: `$databaseFile` is where the database should be, named in the advice when the failure
     * is that file. The original exception message is always kept, since it is what a bug report
     * needs.
     */
    public static function describe(\Throwable $e, ?string $databaseFile = null): string
    {
        $advice = 'Something went wrong while it was setting itself up. If it happens again, please '
            . 'report it with the details below.';

        for ($cause = $e; $cause !== null; $cause = $cause->getPrevious()) {
            if ($cause instanceof ConnectionFailureException || $cause instanceof \PDOException) {
                $advice = 'It could not open its database'
                    . ($databaseFile !== null ? ' (' . $databaseFile . ')' : '')
                    . '. Check that the folder exists and that you can write to it, and that the '
                    . 'disk is not full.';

                break;
            }
        }

        return "Grafida could not start.\n\n"
            . $advice . "\n\n"
            . 'Technical detail: ' . $e::class . ': ' . $e->getMessage();
    }
}
