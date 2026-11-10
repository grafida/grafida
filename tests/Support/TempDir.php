<?php

/**
 * Grafida — Joomla content editing, untethered.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Tests\Support;

/**
 * Removes a test's temporary directory, in PHP: `rm -rf` does not exist on Windows, where the pipe
 * tests run against the packaged worker in the test guests (gh-91).
 */
final class TempDir
{
    public static function remove(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @chmod($path, 0o666);   // Windows will not delete a read-only file
            @unlink($path);

            return;
        }

        if (!is_dir($path)) {
            return;
        }

        foreach ((array) scandir($path) as $name) {
            if ($name !== '.' && $name !== '..' && \is_string($name)) {
                self::remove($path . '/' . $name);
            }
        }

        @rmdir($path);
    }
}
