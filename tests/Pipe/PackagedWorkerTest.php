<?php

/**
 * Grafida — Joomla content editing, untethered.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Tests\Pipe;

use Grafida\Shell\Protocol;
use Grafida\Shell\Request;
use Grafida\Shell\Testing\WorkerProcess;
use PHPUnit\Framework\TestCase;

/**
 * The **packaged** worker (gh-86): the phpmicro SFX stub with the PHAR shell-packager builds
 * beside it, as it ships in the Linux tarball. Skipped unless `GRAFIDA_PIPE_WORKER` names one, e.g.
 * the `grafida-worker` of an extracted `Grafida-*-shell-preview.tar.gz`. With it set,
 * {@see WorkerPipeTest}'s handshake, smoke and conformance tests run against it too.
 *
 * What only a packaged worker can show: Help read out of the `phar://` docs, the language files and
 * migrations extracted into the data directory by `Resources::base()`, and a request body at the
 * shell's limit fitting into the ini block's `memory_limit`.
 */
final class PackagedWorkerTest extends TestCase
{
    private string $home;

    private WorkerProcess $worker;

    protected function setUp(): void
    {
        $binary = getenv('GRAFIDA_PIPE_WORKER');

        if (!\is_string($binary) || $binary === '') {
            self::markTestSkipped('Set GRAFIDA_PIPE_WORKER to a packaged grafida-worker to run these.');
        }

        $this->home = sys_get_temp_dir() . '/grafida-packaged-' . bin2hex(random_bytes(6));
        mkdir($this->home, 0o700, true);
        $env = getenv();

        foreach (['HOME', 'XDG_DATA_HOME', 'XDG_CONFIG_HOME', 'XDG_CACHE_HOME', 'XDG_STATE_HOME', 'APPDATA', 'LOCALAPPDATA', 'USERPROFILE'] as $name) {
            $env[$name] = $this->home;
        }

        $env['SHELL_MAX_BODY_BYTES'] = (string) Protocol::DEFAULT_MAX_BODY_BYTES;
        $this->worker = new WorkerProcess([$binary], \dirname($binary), $env, 30.0);
    }

    protected function tearDown(): void
    {
        if (!isset($this->home)) {
            return;
        }

        unset($this->worker);
        exec('rm -rf ' . escapeshellarg($this->home));
    }

    public function testItRunsFromAPhar(): void
    {
        // The SPA shell and a static asset come out of the PHAR too (no assets/ beside it).
        $index = $this->worker->request(new Request('GET', 'boson://app/'));
        self::assertSame(200, $index->status);
        self::assertStringContainsString('<html', $index->body);
    }

    public function testHelpIsReadFromThePharsDocs(): void
    {
        $contents = $this->worker->request(new Request('GET', 'boson://app/api/help'));
        self::assertSame(200, $contents->status, $contents->body);
        $json = json_decode($contents->body, true);
        self::assertIsArray($json);
        self::assertTrue($json['ok']);

        $slug = self::firstSlug($json);
        self::assertNotNull($slug, 'the table of contents names no page');

        $page = $this->worker->request(new Request('GET', 'boson://app/api/help/page/' . rawurlencode($slug)));
        self::assertSame(200, $page->status, $page->body);
        self::assertGreaterThan(100, \strlen($page->body));
    }

    public function testTheLanguageFilesAndMigrationsAreExtractedIntoTheDataDirectory(): void
    {
        $bootstrap = $this->worker->request(new Request('GET', 'boson://app/api/bootstrap'));
        self::assertSame(200, $bootstrap->status, $bootstrap->body);

        // The data dir (Grafida\Support\Paths): ~/Library/Application Support/Grafida on macOS,
        // $XDG_DATA_HOME/grafida on Linux, %APPDATA%\Grafida on Windows; all under the throwaway home.
        $base      = \PHP_OS_FAMILY === 'Darwin' ? $this->home . '/Library/Application Support' : $this->home;
        $resources = glob($base . '/{,.}*/resources', \GLOB_BRACE | \GLOB_ONLYDIR) ?: [];
        self::assertCount(1, $resources, 'one resources directory under the data dir');
        self::assertFileExists($resources[0] . '/language/en-GB/en-GB.ini');
        self::assertNotEmpty(glob($resources[0] . '/storage/migrations/*.sql'));
    }

    public function testABodyAtTheLimitFitsInMemory(): void
    {
        ini_set('memory_limit', '2G');
        $prefix  = '{"filename":"big.bin","mime":"application/octet-stream","dataBase64":"';
        $suffix  = '"}';
        $base64  = Protocol::DEFAULT_MAX_BODY_BYTES - \strlen($prefix) - \strlen($suffix);
        $base64 -= $base64 % 4;
        $body    = $prefix . str_repeat('QUFB', intdiv($base64, 4)) . $suffix;
        self::assertLessThanOrEqual(Protocol::DEFAULT_MAX_BODY_BYTES, \strlen($body));

        // Site 1 does not exist, so this ends in an error after the decode, which is the point:
        // the worker must survive holding the body, its decoded JSON and the decoded bytes.
        $response = $this->worker->request(new Request('POST', 'boson://app/api/sites/1/media', ['content-type' => 'application/json'], $body), 120.0);
        unset($body);
        $peak = self::peakResidentMiB($this->worker->pid());
        fwrite(\STDERR, \sprintf("\n[packaged worker] %d MiB body → HTTP %d, peak RSS %s MiB\n", Protocol::DEFAULT_MAX_BODY_BYTES >> 20, $response->status, $peak ?? '?'));

        self::assertNotSame(502, $response->status);
        self::assertStringNotContainsString('Allowed memory size', $this->worker->stderr());
        self::assertSame(200, $this->worker->request(new Request('GET', 'boson://app/api/bootstrap'))->status);
    }

    /** @param array<mixed> $node */
    private static function firstSlug(array $node): ?string
    {
        if (isset($node['slug']) && \is_string($node['slug']) && $node['slug'] !== '') {
            return $node['slug'];
        }

        foreach ($node as $child) {
            if (\is_array($child) && ($slug = self::firstSlug($child)) !== null) {
                return $slug;
            }
        }

        return null;
    }

    private static function peakResidentMiB(int $pid): ?int
    {
        $status = @file_get_contents("/proc/{$pid}/status");

        return \is_string($status) && preg_match('/^VmHWM:\s+(\d+) kB/m', $status, $m) === 1 ? intdiv((int) $m[1], 1024) : null;
    }
}
