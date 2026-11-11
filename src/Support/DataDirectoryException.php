<?php

/**
 * Grafida — Joomla content editing, untethered.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Support;

/**
 * A folder under the data directory could not be created (gh-92): the data directory sits under a
 * file, is not writable, or the disk is full.
 *
 * Its own type so that {@see \Grafida\Startup\FailureReporter} can name the folder to a person. The
 * packaged worker writes into the data directory ({@see Resources::base()}) before it opens the
 * database, so this, not a database error, is what a broken data folder looks like there.
 */
final class DataDirectoryException extends \RuntimeException
{
    public function __construct(public readonly string $path, ?\Throwable $previous = null)
    {
        parent::__construct('Cannot create the folder ' . $path, 0, $previous);
    }
}
