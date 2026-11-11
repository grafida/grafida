<?php

/**
 * Grafida — Joomla content editing, untethered.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Tests\Unit;

use Grafida\Http\HttpResponse;
use Grafida\Tests\Unit\Support\FakeTransport;
use Grafida\Update\HostPlatform;
use Grafida\Update\UpdateService;

final class UpdateServiceTest extends TestCase
{
    private const URL = 'https://cdn.example.com/grafida.json';

    public function testCanonicalUpdateUrlUsesGrafidaApp(): void
    {
        $this->assertSame('https://grafida.app/updates/grafida.json', UpdateService::UPDATE_URL);
    }

    private string $cacheFile;

    protected function setUp(): void
    {
        $this->cacheFile = sys_get_temp_dir() . '/grafida-update-test-' . uniqid('', true) . '.json';
    }

    protected function tearDown(): void
    {
        @unlink($this->cacheFile);
    }

    private function service(FakeTransport $http, string $current = '0.1', ?HostPlatform $host = null): UpdateService
    {
        return new UpdateService($http, $current, $this->cacheFile, self::URL, $host);
    }

    /** @param array<string, mixed> $requires */
    private function requiring(array $requires): FakeTransport
    {
        return (new FakeTransport())->on(self::URL, new HttpResponse(200, (string) json_encode([
            'version'  => '0.5',
            'infoURL'  => 'https://github.com/grafida/grafida/releases/tag/0.5',
            'download' => 'https://example.com/Grafida-0.5-macos-arm64.dmg',
            'requires' => $requires,
        ])));
    }

    private const REQUIRES = [
        'macos'   => ['arch' => ['arm64', 'x86_64'], 'min' => '12.0'],
        'windows' => ['arch' => ['x86_64'], 'min' => '10.0.19045'],
        'linux'   => ['arch' => ['x86_64', 'arm64']],
    ];

    public function testAReleaseThisMachineMeetsIsAnUpdate(): void
    {
        foreach ([
            new HostPlatform('macos', 'arm64', '27.2.1'),
            new HostPlatform('macos', 'x86_64', '12.7.6'),
            new HostPlatform('windows', 'x86_64', '10.0.26100'),
            new HostPlatform('windows', 'x86_64', '10.0.19045'),
            new HostPlatform('linux', 'arm64', null),
        ] as $host) {
            @unlink($this->cacheFile);
            $status = $this->service($this->requiring(self::REQUIRES), '0.4', $host)->status();
            $this->assertTrue($status['available'], json_encode($host) ?: '');
            $this->assertSame('0.5', $status['version']);
        }
    }

    public function testAReleaseThatExcludesThisMachineIsNoUpdate(): void
    {
        $intelGone = ['macos' => ['arch' => ['arm64'], 'min' => '12.0']] + self::REQUIRES;

        foreach ([
            'an architecture it no longer lists' => [$intelGone, new HostPlatform('macos', 'x86_64', '27.2')],
            'an OS version below its minimum'   => [self::REQUIRES, new HostPlatform('windows', 'x86_64', '10.0.17763')],
            'an OS below a macOS minimum'       => [['macos' => ['min' => '13.0']], new HostPlatform('macos', 'arm64', '12.7.6')],
            'an OS it does not name'            => [['macos' => ['min' => '12.0']], new HostPlatform('linux', 'x86_64', null)],
            'an architecture never listed'      => [self::REQUIRES, new HostPlatform('windows', 'arm64', '10.0.26100')],
        ] as $case => [$requires, $host]) {
            @unlink($this->cacheFile);
            $status = $this->service($this->requiring($requires), '0.4', $host)->status();
            $this->assertFalse($status['available'], $case);
            $this->assertNull($status['version'], $case);
            $this->assertNull($status['download'], $case);
        }
    }

    public function testWhatCannotBeDetectedOrReadNeverHidesAnUpdate(): void
    {
        foreach ([
            'an unknown OS'                 => [self::REQUIRES, new HostPlatform(null, 'x86_64', null)],
            'an unknown architecture'       => [self::REQUIRES, new HostPlatform('macos', null, '27.2')],
            'an unknown OS version'         => [self::REQUIRES, new HostPlatform('windows', 'x86_64', null)],
            'a requires that is a list'     => [['macos'], new HostPlatform('linux', 'x86_64', null)],
            'an empty requires'             => [[], new HostPlatform('linux', 'x86_64', null)],
            'a minimum that is not numeric' => [['macos' => ['min' => 'Tahoe']], new HostPlatform('macos', 'arm64', '12.0')],
        ] as $case => [$requires, $host]) {
            @unlink($this->cacheFile);
            $this->assertTrue($this->service($this->requiring($requires), '0.4', $host)->status()['available'], $case);
        }
    }

    public function testArchitectureSpellingsAreOne(): void
    {
        $this->assertSame('arm64', HostPlatform::normaliseArch('aarch64'));
        $this->assertSame('x86_64', HostPlatform::normaliseArch('AMD64'));
        $this->assertNull(HostPlatform::normaliseArch('ppc64le'));

        $here = HostPlatform::detect();
        $this->assertNotNull($here->os);
        $this->assertNotNull($here->arch);

        if ($here->os !== 'linux') {
            $this->assertMatchesRegularExpression('/^[0-9]+\.[0-9]+/', (string) $here->version);
        }
    }

    private function payload(string $version): HttpResponse
    {
        return new HttpResponse(200, json_encode([
            'version'  => $version,
            'date'     => '2026-07-01',
            'infoURL'  => 'https://github.com/grafida/grafida/releases/tag/' . $version,
            'download' => 'https://example.com/Grafida-' . $version . '.dmg',
        ]));
    }

    public function testReportsANewerVersionAndCachesTheDocument(): void
    {
        $http   = (new FakeTransport())->on(self::URL, $this->payload('0.2'));
        $status = $this->service($http)->status();

        $this->assertTrue($status['available']);
        $this->assertSame('0.2', $status['version']);
        $this->assertSame('https://github.com/grafida/grafida/releases/tag/0.2', $status['infoURL']);
        $this->assertSame('https://example.com/Grafida-0.2.dmg', $status['download']);
        $this->assertFileExists($this->cacheFile);
        $this->assertCount(1, $http->requests);
    }

    public function testSameVersionIsNotAnUpdate(): void
    {
        $http   = (new FakeTransport())->on(self::URL, $this->payload('0.1'));
        $status = $this->service($http)->status();

        $this->assertFalse($status['available']);
        $this->assertNull($status['version']);
        $this->assertNull($status['download']);
    }

    public function testFreshCacheIsNotRefetched(): void
    {
        file_put_contents($this->cacheFile, json_encode(['version' => '0.2', 'infoURL' => 'x']));

        $http   = new FakeTransport();
        $status = $this->service($http)->status();

        $this->assertTrue($status['available']);
        $this->assertSame([], $http->requests, 'A fresh cache must not trigger a network fetch.');
    }

    public function testStaleCacheIsRefetched(): void
    {
        file_put_contents($this->cacheFile, json_encode(['version' => '0.2']));
        // Backdate the cache well beyond the 12-hour window.
        touch($this->cacheFile, time() - 13 * 60 * 60);

        $http = (new FakeTransport())->on(self::URL, $this->payload('0.3'));

        $status = $this->service($http)->status();

        $this->assertSame('0.3', $status['version']);
        $this->assertCount(1, $http->requests);
    }

    public function testFailedFetchWithoutCacheWritesEmptyDocument(): void
    {
        $http   = (new FakeTransport())->throwFor(self::URL);
        $status = $this->service($http)->status();

        $this->assertFalse($status['available']);
        $this->assertFileExists($this->cacheFile);
        $this->assertSame('{}', trim((string) file_get_contents($this->cacheFile)));
    }

    public function testFailedFetchFallsBackToExistingCache(): void
    {
        file_put_contents($this->cacheFile, json_encode(['version' => '0.9']));
        touch($this->cacheFile, time() - 13 * 60 * 60);

        $http   = (new FakeTransport())->throwFor(self::URL);
        $status = $this->service($http)->status();

        $this->assertTrue($status['available']);
        $this->assertSame('0.9', $status['version']);
    }
}
