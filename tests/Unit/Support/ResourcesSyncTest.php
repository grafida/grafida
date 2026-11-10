<?php

/**
 * Grafida — Joomla content editing, untethered.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Tests\Unit\Support;

use Grafida\Support\Resources;
use Grafida\Tests\Support\TempDir;
use PHPUnit\Framework\TestCase;

/**
 * The extraction of the language files and migrations out of a packaged PHAR (gh-86). The source
 * here is a plain directory: `sync()` treats a `phar://` tree and a directory alike, and the
 * packaged case itself is covered by tests/Pipe/PackagedWorkerTest against a real build.
 */
final class ResourcesSyncTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/grafida-resources-' . bin2hex(random_bytes(6));
        mkdir($this->dir . '/src/en-GB', 0o700, true);
        mkdir($this->dir . '/src/xx-XX', 0o700, true);
        file_put_contents($this->dir . '/src/en-GB/en-GB.ini', 'GRAFIDA_A="Teh text"');
        file_put_contents($this->dir . '/src/xx-XX/xx-XX.ini', 'GRAFIDA_A="x"');
    }

    protected function tearDown(): void
    {
        TempDir::remove($this->dir);
    }

    private function sync(): void
    {
        $method = new \ReflectionMethod(Resources::class, 'sync');
        $method->invoke(null, $this->dir . '/src', $this->dir . '/dest');
    }

    public function testAnEditThatKeepsTheLengthIsStillExtracted(): void
    {
        $this->sync();
        self::assertSame('GRAFIDA_A="Teh text"', file_get_contents($this->dir . '/dest/en-GB/en-GB.ini'));

        file_put_contents($this->dir . '/src/en-GB/en-GB.ini', 'GRAFIDA_A="The text"');
        $this->sync();
        self::assertSame('GRAFIDA_A="The text"', file_get_contents($this->dir . '/dest/en-GB/en-GB.ini'));
    }

    public function testWhatIsNoLongerShippedIsRemoved(): void
    {
        $this->sync();
        self::assertFileExists($this->dir . '/dest/xx-XX/xx-XX.ini');

        unlink($this->dir . '/src/xx-XX/xx-XX.ini');
        rmdir($this->dir . '/src/xx-XX');
        $this->sync();

        self::assertDirectoryDoesNotExist($this->dir . '/dest/xx-XX');
        self::assertFileExists($this->dir . '/dest/en-GB/en-GB.ini');
    }
}
