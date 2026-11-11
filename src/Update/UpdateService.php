<?php

/**
 * Grafida — Joomla content editing, untethered.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Update;

use Grafida\Http\HttpException;
use Grafida\Http\Transport;

/**
 * Checks whether a newer version of Grafida is available.
 *
 * The update information is published to the CDN as a small JSON document (built
 * at release time by the {@see \tasks\UpdateJson} Phing task) describing the
 * latest stable release: its {@code version}, {@code infoURL} (the GitHub release
 * page) and {@code download} URL.
 *
 * To avoid hammering the CDN, the fetched document is cached in a per-user file
 * and only refreshed when that file is older than 12 hours (the "last fetched"
 * timestamp is simply the file's modification time). A failed fetch with no prior
 * cache still writes an empty {@code {}} document, so a broken network does not
 * trigger a fetch on every startup.
 *
 * A release may carry an optional {@code requires} member (gh-92), per OS:
 * {@code {"macos": {"arch": ["arm64", "x86_64"], "min": "12.0"}, "windows": {…}, "linux": {…}}}.
 * When it is there, a release whose {@code requires} has no entry for this OS, lists
 * architectures that do not include this build's, or names a {@code min} above this
 * OS version is "no update": it is how a platform that stops being supported (Intel
 * Macs, from macOS 29) stops being told about releases it cannot run. Every client up
 * to 0.3 reads only {@code version}, {@code infoURL} and {@code download}, so it
 * ignores the member. Something about this machine we cannot detect never refuses an
 * update (see {@see HostPlatform}), and neither does a malformed member.
 */
final class UpdateService
{
    /** Canonical URL the release pipeline publishes the update information to. */
    public const UPDATE_URL = 'https://grafida.app/updates/grafida.json';

    /** Refresh the cached information at most once every 12 hours. */
    private const MAX_AGE = 12 * 60 * 60;

    public function __construct(
        private readonly Transport $http,
        private readonly string $currentVersion,
        private readonly string $cacheFile,
        private readonly string $updateUrl = self::UPDATE_URL,
        private readonly ?HostPlatform $host = null,
    ) {}

    /**
     * Returns the update status, refreshing the cache from the CDN when stale.
     *
     * @return array{available: bool, version: string|null, infoURL: string|null, download: string|null}
     */
    public function status(): array
    {
        return $this->evaluate($this->cachedOrFresh());
    }

    /**
     * Reads the cached update information, fetching a fresh copy when the cache
     * is missing or older than {@see MAX_AGE}.
     *
     * @return array<array-key, mixed>
     */
    private function cachedOrFresh(): array
    {
        if (is_file($this->cacheFile)) {
            $age = time() - (int) @filemtime($this->cacheFile);

            if ($age >= 0 && $age < self::MAX_AGE) {
                return $this->readCache();
            }
        }

        return $this->fetch();
    }

    /**
     * Fetches the update information from the CDN (best-effort).
     *
     * On any failure it falls back to a previously-cached copy; if there is none,
     * it writes an empty {@code {}} document so the 12-hour back-off applies to
     * failed attempts too.
     *
     * @return array<array-key, mixed>
     */
    private function fetch(): array
    {
        try {
            $response = $this->http->request('GET', $this->updateUrl, [
                'Accept'     => 'application/json',
                'User-Agent' => 'Grafida/' . $this->currentVersion,
            ]);

            if ($response->isSuccess()) {
                $data = json_decode($response->body, true);

                if (is_array($data)) {
                    $this->writeCache($response->body);

                    return $data;
                }
            }
        } catch (HttpException) {
            // Network/transport failure — handled below.
        }

        if (is_file($this->cacheFile)) {
            return $this->readCache();
        }

        // No cache and the fetch failed: record the attempt with an empty document.
        $this->writeCache('{}');

        return [];
    }

    /** @return array<array-key, mixed> */
    private function readCache(): array
    {
        $raw = @file_get_contents($this->cacheFile);

        if ($raw === false) {
            return [];
        }

        $data = json_decode($raw, true);

        return is_array($data) ? $data : [];
    }

    private function writeCache(string $json): void
    {
        $dir = \dirname($this->cacheFile);

        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }

        @file_put_contents($this->cacheFile, $json);
    }

    /**
     * The version number in the document's `version`: its first run of dot-separated digits.
     *
     * Up to 0.3 the published document took the GitHub release's name, "Grafida 0.3", and
     * version_compare() ranks a leading word below every number, so no update was ever offered
     * (gh-92). UpdateJson now writes the tag; this reads either, and an older document too.
     */
    private static function versionNumber(string $version): string
    {
        return preg_match('/\d+(?:\.\d+)*/', $version, $m) === 1 ? $m[0] : '';
    }

    /**
     * Turns the cached update document into the status returned to the SPA.
     *
     * @param array<array-key, mixed> $info
     *
     * @return array{available: bool, version: string|null, infoURL: string|null, download: string|null}
     */
    private function evaluate(array $info): array
    {
        $version  = isset($info['version']) && is_string($info['version']) ? self::versionNumber($info['version']) : '';
        $infoURL  = isset($info['infoURL']) && is_string($info['infoURL']) ? $info['infoURL'] : '';
        $download = isset($info['download']) && is_string($info['download']) ? $info['download'] : '';

        $available = $version !== ''
            && version_compare($version, $this->currentVersion, '>')
            && $this->runsHere($info['requires'] ?? null);

        return [
            'available' => $available,
            'version'   => $available ? $version : null,
            'infoURL'   => $available && $infoURL !== '' ? $infoURL : null,
            'download'  => $available && $download !== '' ? $download : null,
        ];
    }

    /** Whether a release with this {@code requires} member can run on this machine. */
    private function runsHere(mixed $requires): bool
    {
        if (!\is_array($requires) || $requires === [] || array_is_list($requires)) {
            return true; // absent or malformed: no restriction
        }

        $host = $this->host ?? HostPlatform::detect();

        if ($host->os === null) {
            return true;
        }

        $entry = $requires[$host->os] ?? null;

        if (!\is_array($entry)) {
            return false; // the release names its platforms, and this is not one of them
        }

        $arches = $entry['arch'] ?? null;

        if (\is_array($arches) && $host->arch !== null) {
            $listed = [];

            foreach ($arches as $a) {
                $listed[] = \is_string($a) ? HostPlatform::normaliseArch($a) : null;
            }

            if (!\in_array($host->arch, $listed, true)) {
                return false;
            }
        }

        $min = $entry['min'] ?? null;

        if (\is_string($min) && preg_match('/^[0-9]+(\.[0-9]+)*$/', $min) === 1 && $host->version !== null) {
            return version_compare($host->version, $min, '>=');
        }

        return true;
    }
}
