<?php

/**
 * Grafida — Joomla content editing, untethered.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Tests\Unit\Startup;

use Grafida\Startup\FailureReporter;
use Grafida\Support\DataDirectoryException;
use Grafida\Tests\Unit\TestCase;
use Joomla\Database\Exception\ConnectionFailureException;

final class FailureReporterTest extends TestCase
{
    public function testADatabaseThatCannotBeOpenedSaysSoAndWhere(): void
    {
        $e    = new ConnectionFailureException('Could not connect to PDO: unable to open database file', 0, new \PDOException('unable to open database file'));
        $text = FailureReporter::describe($e, '/home/u/.local/share/grafida/grafida.sqlite');

        $this->assertStringStartsWith("Grafida could not start.\n\n", $text);
        $this->assertStringContainsString('could not open its database (/home/u/.local/share/grafida/grafida.sqlite)', $text);
        $this->assertStringContainsString('write to it', $text);
    }

    public function testADatabaseFailureFurtherDownTheChainIsFound(): void
    {
        $text = FailureReporter::describe(new \RuntimeException('migration failed', 0, new \PDOException('disk I/O error')));

        $this->assertStringContainsString('could not open its database.', $text);
    }

    /**
     * The packaged worker copies its language files and migrations into the data folder before it
     * opens the database, so a folder it cannot write to fails there first (gh-92).
     */
    public function testADataFolderThatCannotBeWrittenSaysSoAndWhere(): void
    {
        $e    = new \RuntimeException('could not build the app', 0, new DataDirectoryException('/home/u/.local/share/grafida/resources/language'));
        $text = FailureReporter::describe($e, '/home/u/.local/share/grafida/grafida.sqlite');

        $this->assertStringStartsWith("Grafida could not start.\n\n", $text);
        $this->assertStringContainsString('could not write to its data folder (/home/u/.local/share/grafida/resources/language)', $text);
        $this->assertStringContainsString('write to it', $text);
        $this->assertStringNotContainsString('database', $text);
    }

    public function testAnyOtherFailureStillGetsAnExplanation(): void
    {
        $text = FailureReporter::describe(new \LogicException('boom'));

        $this->assertStringStartsWith("Grafida could not start.\n\n", $text);
        $this->assertStringContainsString('report it', $text);
        $this->assertStringNotContainsString('database', $text);
    }

    public function testTheOriginalErrorIsAlwaysKept(): void
    {
        $text = FailureReporter::describe(new \RuntimeException('the precise reason'));

        $this->assertStringEndsWith('Technical detail: RuntimeException: the precise reason', $text);
    }
}
