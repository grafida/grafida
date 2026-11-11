<?php

/**
 * Grafida — Joomla content editing, untethered.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Tests\Feature;

use Grafida\Application\Kernel;
use Grafida\Media\MediaRepository;
use Grafida\Shell\Request;
use Grafida\Tests\Support\TestContainer;
use Grafida\Tests\Support\TestDatabase;
use Joomla\Database\DatabaseInterface;
use PHPUnit\Framework\TestCase;

/**
 * Files and folders picked in the native shell's dialogs, and the clipboard the shell reads
 * (grafida/grafida#85).
 *
 * Under the shell the SPA never sees a path: it hands back the dialog's token, and the shell
 * forwards the resolved path as `X-Shell-File-Path`. These are the endpoints that read it. Their
 * answers without the header (503 for a dialog, the body's `directory` for an export) are covered,
 * unchanged, by ApiRoutingTest and LocalMediaTest.
 */
final class ShellFileTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/grafida-shell-file-test-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }

        rmdir($this->dir);
    }

    /**
     * @param array<string, string> $headers
     *
     * @return array{0: int, 1: mixed}
     */
    private function call(Kernel $kernel, string $method, string $path, array $headers = [], string $body = ''): array
    {
        $response = $kernel->handle(new Request($method, 'boson://app' . $path, $headers, $body));

        return [$response->status, json_decode($response->body, true)];
    }

    private function seedSite(DatabaseInterface $db): int
    {
        $now = gmdate('Y-m-d H:i:s');
        $pdo = TestDatabase::connection($db);
        $pdo->prepare('INSERT INTO sites (title, base_url, created_at, updated_at) VALUES (?, ?, ?, ?)')
            ->execute(['Site', 'https://example.test', $now, $now]);

        return (int) $pdo->lastInsertId();
    }

    public function testOpenFileReadsThePathTheShellResolved(): void
    {
        file_put_contents($this->dir . '/picked.png', 'PNGDATA');
        $kernel = TestContainer::create()->get(Kernel::class);

        // No dialog in this container: under the shell, PHP never opens one.
        [$status, $json] = $this->call($kernel, 'POST', '/api/dialog/open-file', ['X-Shell-File-Path' => $this->dir . '/picked.png'], '{"filter":"image"}');

        self::assertSame(200, $status);
        self::assertTrue($json['ok']);
        self::assertSame('picked.png', $json['data']['name']);
        self::assertSame('image/png', $json['data']['mime']);
        self::assertSame('PNGDATA', base64_decode($json['data']['dataBase64']));
    }

    public function testAPickedFileThatHasGoneIsA400(): void
    {
        $kernel = TestContainer::create()->get(Kernel::class);

        [$status, $json] = $this->call($kernel, 'POST', '/api/dialog/open-file', ['X-Shell-File-Path' => $this->dir . '/gone.png']);

        self::assertSame(400, $status);
        self::assertFalse($json['ok']);
    }

    public function testDraftExportGoesToTheShellsFolder(): void
    {
        $container = TestContainer::create();
        $kernel    = $container->get(Kernel::class);
        $siteId    = $this->seedSite($container->get(DatabaseInterface::class));

        [, $created] = $this->call($kernel, 'POST', '/api/sites/' . $siteId . '/drafts', [], (string) json_encode(['title' => 'Hello', 'alias' => 'hello', 'html' => '<p>Body</p>']));

        // The folder header wins over anything the body says.
        [$status, $exported] = $this->call(
            $kernel,
            'POST',
            '/api/drafts/' . $created['data']['id'] . '/export',
            ['X-Shell-File-Path' => $this->dir],
            (string) json_encode(['directory' => '/nonexistent']),
        );

        self::assertSame(200, $status, (string) json_encode($exported));
        self::assertSame($this->dir, \dirname($exported['data']['path']));
        self::assertFileExists($exported['data']['path']);
    }

    public function testRequestLogExportGoesToTheShellsFolder(): void
    {
        $kernel = TestContainer::create()->get(Kernel::class);

        [$status, $json] = $this->call($kernel, 'POST', '/api/request-log/export', ['X-Shell-File-Path' => $this->dir], '{}');

        self::assertSame(200, $status, (string) json_encode($json));
        self::assertSame($this->dir, \dirname($json['data']['path']));
        self::assertFileExists($json['data']['path']);
    }

    public function testLocalMediaIsSavedToTheShellsFolder(): void
    {
        $container = TestContainer::create();
        $kernel    = $container->get(Kernel::class);
        $siteId    = $this->seedSite($container->get(DatabaseInterface::class));
        $id        = $container->get(MediaRepository::class)->store($siteId, null, 'photo.png', 'image/png', 'raw-image-bytes');

        [$status, $json] = $this->call($kernel, 'POST', '/api/media/' . $id . '/save-to-disk', ['X-Shell-File-Path' => $this->dir], '{}');

        self::assertSame(200, $status, (string) json_encode($json));
        self::assertSame('raw-image-bytes', file_get_contents($json['data']['path']));
        self::assertSame($this->dir, \dirname($json['data']['path']));
    }
    public function testTheClipboardTheShellReadIsNormalisedLikeAnyOther(): void
    {
        $kernel = TestContainer::create()->get(Kernel::class);

        // CRLF and a zero-width space, as a Windows document or an AI reply leaves them.
        [$status, $json] = $this->call($kernel, 'GET', '/api/clipboard/text', ['X-Shell-Clipboard' => 'text'], "Καλη\u{200B}μέρα\r\nsecond line");

        self::assertSame(200, $status);
        self::assertSame("Καλημέρα\nsecond line", $json['data']['text']);
    }

    public function testAnEmptyClipboardFromTheShellIsEmptyNotUnreadable(): void
    {
        $kernel = TestContainer::create()->get(Kernel::class);

        [$status, $json] = $this->call($kernel, 'GET', '/api/clipboard/text', ['X-Shell-Clipboard' => 'text']);

        self::assertSame(200, $status);
        self::assertSame('', $json['data']['text']);
    }
}
