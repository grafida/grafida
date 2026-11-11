<?php

/**
 * Grafida — Joomla content editing, untethered.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Update;

/**
 * What a release's `requires` member is matched against (gh-92): the OS family, the architecture
 * this build runs as, and the OS version.
 *
 * - **os** is `macos`, `windows` or `linux` (the keys of `requires`), or null for anything else.
 * - **arch** is `arm64` or `x86_64`, the names `requires` uses. It is the architecture of this
 *   build, which is what decides which download fits: an x86_64 build running under Rosetta 2 on an
 *   Apple Silicon Mac reports x86_64, exactly as `php_uname('m')` sees it in the translated process.
 * - **version** is the macOS product version (from `SystemVersion.plist`, read rather than spawning
 *   `sw_vers`), Windows' `10.0.<build>` (so Windows 10 22H2 is `10.0.19045` and every Windows 11 is
 *   above it), and null on Linux, whose kernel version says nothing a release could require.
 *
 * Anything undetectable is null, and the check treats null as "no reason to refuse": a release is
 * never hidden from a machine merely because we could not identify it.
 */
final readonly class HostPlatform
{
    public function __construct(
        public ?string $os,
        public ?string $arch,
        public ?string $version,
    ) {}

    public static function detect(): self
    {
        $os = match (\PHP_OS_FAMILY) {
            'Darwin'  => 'macos',
            'Windows' => 'windows',
            'Linux'   => 'linux',
            default   => null,
        };

        return new self($os, self::normaliseArch(php_uname('m')), match ($os) {
            'macos'   => self::macosVersion(),
            'windows' => self::windowsVersion(),
            default   => null,
        });
    }

    /** `requires`' spelling: arm64 or x86_64; null for anything else. */
    public static function normaliseArch(string $machine): ?string
    {
        return match (strtolower(trim($machine))) {
            'arm64', 'aarch64'        => 'arm64',
            'x86_64', 'amd64', 'x64'  => 'x86_64',
            default                   => null,
        };
    }

    private static function macosVersion(): ?string
    {
        $plist = @file_get_contents('/System/Library/CoreServices/SystemVersion.plist');

        if (\is_string($plist)
            && preg_match('#<key>ProductVersion</key>\s*<string>([0-9]+(?:\.[0-9]+)*)</string>#', $plist, $m) === 1) {
            return $m[1];
        }

        return null;
    }

    private static function windowsVersion(): ?string
    {
        // php_uname('r') is "10.0"; the build is in php_uname('v'), "build 19045 (Windows 10 …)".
        $release = php_uname('r');

        if (preg_match('/^[0-9]+\.[0-9]+$/', $release) === 1 && preg_match('/build ([0-9]+)/i', php_uname('v'), $m) === 1) {
            return $release . '.' . $m[1];
        }

        return null;
    }
}
