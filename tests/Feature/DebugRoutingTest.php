<?php

/**
 * Grafida — Joomla content editing, untethered.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Tests\Feature;

use Boson\Component\Http\Request;
use Grafida\Application\Kernel;
use Grafida\Debug\RecordingTransport;
use Grafida\Debug\RequestLog;
use Grafida\Http\HttpResponse;
use Grafida\Tests\Support\TestContainer;
use Grafida\Tests\Unit\Support\FakeTransport;
use Joomla\DI\Container;
use PHPUnit\Framework\TestCase;

/**
 * Exercises gh-37's Debug-mode routes end-to-end through the kernel: the
 * Diagnose Connection probe (`POST /api/sites/diagnose`) and the Request Log
 * (`/api/request-log*`, `/api/settings/request-log`). The Feature suite is
 * the API contract, so this drives `Kernel::handle()` exactly like
 * `ApiRoutingTest` and asserts status + JSON shape rather than internals.
 */
final class DebugRoutingTest extends TestCase
{
    /** @var list<string> */
    private array $tempDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $dir) {
            foreach (glob($dir . '/*') ?: [] as $file) {
                @unlink($file);
            }

            @rmdir($dir);
        }

        $this->tempDirs = [];
    }

    private function kernel(): Kernel
    {
        return TestContainer::create()->get(Kernel::class);
    }

    /**
     * Overrides the shared `http.default` transport with a fake one wrapped in
     * the same {@see RecordingTransport} the real container uses, so an
     * outbound "site" request records into the shared Request Log without
     * touching the network. The override must happen *before* `Kernel::class`
     * is resolved: `ApiController`'s constructor eagerly builds every
     * controller (and therefore `SiteController` -> `ApiClient` ->
     * 'http.default') up front, so overriding afterwards would be too late.
     */
    private function kernelWithFakeTransport(FakeTransport $fake): Kernel
    {
        $container = TestContainer::create();
        $container->set(
            'http.default',
            static fn (Container $c): RecordingTransport => new RecordingTransport($fake, $c->get(RequestLog::class)),
            true,
        );

        return $container->get(Kernel::class);
    }

    /** @return array{0: int, 1: mixed} */
    private function call(Kernel $kernel, string $method, string $path, ?string $body = null): array
    {
        $request  = new Request($method, 'boson://app' . $path, [], $body ?? '');
        $response = $kernel->handle($request);

        return [(int) (string) $response->status, json_decode((string) $response->body, true)];
    }

    private function tempDir(): string
    {
        $dir = sys_get_temp_dir() . '/grafida-debug-test-' . uniqid();
        mkdir($dir);
        $this->tempDirs[] = $dir;

        return $dir;
    }

    /** Case-insensitive header lookup, since redaction preserves whatever casing the caller used. */
    private static function header(array $headers, string $name): ?string
    {
        foreach ($headers as $key => $value) {
            if (strtolower((string) $key) === strtolower($name)) {
                return (string) $value;
            }
        }

        return null;
    }

    /** A kernel wired with a fake transport that answers the first candidate API base as a working site. */
    private function kernelWithWorkingSite(): Kernel
    {
        $fake = (new FakeTransport())->on(
            'https://example.test/api/index.php/v1/users/levels',
            new HttpResponse(200, '{"data":[]}', ['content-type' => 'application/vnd.api+json'])
        );

        return $this->kernelWithFakeTransport($fake);
    }

    // ------------------------------------------------------------------
    //  Diagnose Connection
    // ------------------------------------------------------------------

    public function testDiagnoseUnreachableUrlReturnsAttemptsWithoutApiBase(): void
    {
        $token = 'diagnose-secret-token-0123456789';

        [$status, $json] = $this->call(
            $this->kernel(),
            'POST',
            '/api/sites/diagnose',
            json_encode(['url' => 'https://127.0.0.1:1', 'token' => $token])
        );

        self::assertSame(200, $status);
        self::assertTrue($json['ok']);
        self::assertNull($json['data']['apiBase']);
        self::assertNotNull($json['data']['error']);
        self::assertIsArray($json['data']['attempts']);
        self::assertNotEmpty($json['data']['attempts']);

        foreach ($json['data']['attempts'] as $attempt) {
            $auth = self::header($attempt['request']['headers'], 'Authorization');

            self::assertNotNull($auth);
            self::assertStringNotContainsString($token, $auth);
            self::assertStringContainsString('.', $auth);
        }
    }

    public function testDiagnoseRouteIsNotShadowedBySiteIdRoute(): void
    {
        [$status, $json] = $this->call(
            $this->kernel(),
            'POST',
            '/api/sites/diagnose',
            json_encode(['url' => 'https://127.0.0.1:1', 'token' => 'x'])
        );

        self::assertSame(200, $status);
        self::assertIsArray($json['data']);
        self::assertArrayHasKey('apiBase', $json['data']);
        self::assertArrayHasKey('attempts', $json['data']);
        // A site resource carries a title/baseUrl; this must not be one, nor a 404.
        self::assertArrayNotHasKey('title', $json['data']);
        self::assertArrayNotHasKey('baseUrl', $json['data']);
    }

    // ------------------------------------------------------------------
    //  Request Log
    // ------------------------------------------------------------------

    public function testRequestLogDefaultsToDisabledAndEmpty(): void
    {
        [$status, $json] = $this->call($this->kernel(), 'GET', '/api/request-log');

        self::assertSame(200, $status);
        self::assertTrue($json['ok']);
        self::assertFalse($json['data']['enabled']);
        self::assertSame([], $json['data']['entries']);
    }

    public function testEnablingRequestLogPersistsAndReflectsInBootstrap(): void
    {
        $kernel = $this->kernel();

        [$status, $json] = $this->call($kernel, 'POST', '/api/settings/request-log', json_encode(['enabled' => true]));

        self::assertSame(200, $status);
        self::assertTrue($json['data']['requestLog']);

        [, $boot] = $this->call($kernel, 'GET', '/api/bootstrap');
        self::assertTrue($boot['data']['requestLog']);
    }

    public function testEnabledRequestLogRecordsAnOutboundCall(): void
    {
        $kernel = $this->kernelWithWorkingSite();

        [, $json] = $this->call($kernel, 'POST', '/api/settings/request-log', json_encode(['enabled' => true]));
        self::assertTrue($json['data']['requestLog']);

        [$status] = $this->call(
            $kernel,
            'POST',
            '/api/sites/test',
            json_encode(['url' => 'https://example.test', 'token' => 'tok'])
        );
        self::assertSame(200, $status);

        [, $log] = $this->call($kernel, 'GET', '/api/request-log');
        self::assertTrue($log['data']['enabled']);
        self::assertNotEmpty($log['data']['entries']);
    }

    public function testDisablingRequestLogEmptiesEntries(): void
    {
        $kernel = $this->kernelWithWorkingSite();

        $this->call($kernel, 'POST', '/api/settings/request-log', json_encode(['enabled' => true]));
        $this->call($kernel, 'POST', '/api/sites/test', json_encode(['url' => 'https://example.test', 'token' => 'tok']));

        [, $log] = $this->call($kernel, 'GET', '/api/request-log');
        self::assertNotEmpty($log['data']['entries']);

        [$status, $json] = $this->call($kernel, 'POST', '/api/settings/request-log', json_encode(['enabled' => false]));
        self::assertSame(200, $status);
        self::assertFalse($json['data']['requestLog']);

        [, $log] = $this->call($kernel, 'GET', '/api/request-log');
        self::assertSame([], $log['data']['entries']);
    }

    public function testClearRequestLogEmptiesEntries(): void
    {
        $kernel = $this->kernelWithWorkingSite();

        $this->call($kernel, 'POST', '/api/settings/request-log', json_encode(['enabled' => true]));
        $this->call($kernel, 'POST', '/api/sites/test', json_encode(['url' => 'https://example.test', 'token' => 'tok']));

        [, $log] = $this->call($kernel, 'GET', '/api/request-log');
        self::assertNotEmpty($log['data']['entries']);

        [$status, $json] = $this->call($kernel, 'POST', '/api/request-log/clear');
        self::assertSame(200, $status);
        self::assertTrue($json['ok']);

        [, $log] = $this->call($kernel, 'GET', '/api/request-log');
        self::assertSame([], $log['data']['entries']);
    }

    public function testBootstrapClearsRequestLog(): void
    {
        $kernel = $this->kernelWithWorkingSite();

        $this->call($kernel, 'POST', '/api/settings/request-log', json_encode(['enabled' => true]));
        $this->call($kernel, 'POST', '/api/sites/test', json_encode(['url' => 'https://example.test', 'token' => 'tok']));

        [, $log] = $this->call($kernel, 'GET', '/api/request-log');
        self::assertNotEmpty($log['data']['entries']);

        $this->call($kernel, 'GET', '/api/bootstrap');

        [, $log] = $this->call($kernel, 'GET', '/api/request-log');
        self::assertSame([], $log['data']['entries']);
    }

    public function testLastSiteChangeClearsRequestLog(): void
    {
        $kernel = $this->kernelWithWorkingSite();

        $this->call($kernel, 'POST', '/api/settings/request-log', json_encode(['enabled' => true]));
        $this->call($kernel, 'POST', '/api/sites/test', json_encode(['url' => 'https://example.test', 'token' => 'tok']));

        [, $log] = $this->call($kernel, 'GET', '/api/request-log');
        self::assertNotEmpty($log['data']['entries']);

        $this->call($kernel, 'POST', '/api/settings/last-site', json_encode(['siteId' => 5]));

        [, $log] = $this->call($kernel, 'GET', '/api/request-log');
        self::assertSame([], $log['data']['entries']);
    }

    public function testExportWritesARedactedJsonFile(): void
    {
        $kernel = $this->kernelWithWorkingSite();
        $token  = 'export-secret-token-9876543210';

        $this->call($kernel, 'POST', '/api/settings/request-log', json_encode(['enabled' => true]));
        $this->call($kernel, 'POST', '/api/sites/test', json_encode(['url' => 'https://example.test', 'token' => $token]));

        $dir = $this->tempDir();

        [$status, $json] = $this->call($kernel, 'POST', '/api/request-log/export', json_encode(['directory' => $dir]));

        self::assertSame(200, $status);
        self::assertTrue($json['ok']);
        self::assertFileExists($json['data']['path']);

        $contents = (string) file_get_contents($json['data']['path']);
        $decoded  = json_decode($contents, true);

        self::assertIsArray($decoded);
        self::assertArrayHasKey('entries', $decoded);
        self::assertNotEmpty($decoded['entries']);
        self::assertStringNotContainsString($token, $contents);

        unlink($json['data']['path']);
    }

    public function testExportRequiresAWritableDirectory(): void
    {
        $kernel = $this->kernel();

        [$status, $json] = $this->call($kernel, 'POST', '/api/request-log/export', json_encode(['directory' => '']));

        self::assertSame(400, $status);
        self::assertFalse($json['ok']);
    }

    // ------------------------------------------------------------------
    //  Router contract
    // ------------------------------------------------------------------

    public function testUnknownMethodOnRegisteredDiagnoseRouteIs405(): void
    {
        [$status, $json] = $this->call($this->kernel(), 'GET', '/api/sites/diagnose');

        self::assertSame(405, $status);
        self::assertFalse($json['ok']);
    }

    public function testUnknownMethodOnRegisteredRequestLogRouteIs405(): void
    {
        [$status, $json] = $this->call($this->kernel(), 'PUT', '/api/request-log');

        self::assertSame(405, $status);
        self::assertFalse($json['ok']);
    }

    public function testUnknownRequestLogPathIs404(): void
    {
        [$status, $json] = $this->call($this->kernel(), 'GET', '/api/request-log/nope');

        self::assertSame(404, $status);
        self::assertFalse($json['ok']);
    }
}
