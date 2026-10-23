<?php

/**
 * Grafida — Joomla content editing, untethered.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

/*
 * Compile a SINGLE Boson target out of the multi-target boson.json.
 *
 * `boson compile` always builds every entry in boson.json's `target` array in one
 * pass — there is no CLI flag to pick just one OS/arch. Maintaining a separate
 * boson.<os>.json per platform would mean six files kept in lock-step, so instead
 * this script filters the master boson.json down to the one requested target AT
 * RUNTIME, writes the result to a throwaway config and compiles that.
 *
 * Usage:
 *   php build/tasks/compile-target.php --type=macos   --arch=arm64
 *   php build/tasks/compile-target.php --type=macos   --arch=amd64
 *   php build/tasks/compile-target.php --type=windows --arch=amd64
 *   php build/tasks/compile-target.php --type=linux   --arch=amd64
 *   php build/tasks/compile-target.php --type=linux   --arch=arm64
 *   php build/tasks/compile-target.php --type=phar
 *   php build/tasks/compile-target.php --all
 *   php build/tasks/compile-target.php --pack-only            (just build build/.temp/grafida.phar)
 *   php build/tasks/compile-target.php --type=... --no-pack   (compile, reusing that PHAR)
 *
 * The application PHAR is identical for every target, so build.xml packs it once (--pack-only,
 * the `pack` target) and each platform target then passes --no-pack to reuse it. Without either
 * flag the script packs and compiles in one go, as before.
 *
 * The `phar` target carries no architecture, so --arch is omitted for it.
 * --all keeps every target (scripts/build-all.sh uses it) — it still goes
 * through this script so the custom-SFX injection and output-dir pre-cleaning
 * below apply to the all-targets build too.
 *
 * Boson resolves `directories`/`finder` paths relative to the config's `root`
 * (which otherwise defaults to the config file's own directory), while `output`
 * is resolved relative to the current working directory. The throwaway config
 * therefore pins `root` to the project root explicitly, and we must be run from
 * the project root so `output: ./build` lands in the right place — both of which
 * the Phing build.xml guarantees.
 */

$root = \dirname(__DIR__, 2);
\chdir($root);

// ---------------------------------------------------------------------------
// Parse --type / --arch arguments
// ---------------------------------------------------------------------------
$options = \getopt('', ['type:', 'arch::', 'config::', 'all', 'pack-only', 'no-pack']);
$all     = isset($options['all']);
$packOnly = isset($options['pack-only']);
$noPack   = isset($options['no-pack']);
$type    = isset($options['type']) ? (string) $options['type'] : '';
$arch    = isset($options['arch']) ? (string) $options['arch'] : '';
$config  = isset($options['config']) ? (string) $options['config'] : ($root . '/boson.json');

if (!$all && !$packOnly && $type === '')
{
    \fwrite(\STDERR, "ERROR: the --type argument is required (macos|windows|linux|phar), or pass --all.\n");
    exit(2);
}

if (!$all && !$packOnly && $type !== 'phar' && $arch === '')
{
    \fwrite(\STDERR, "ERROR: the --arch argument is required for the '{$type}' target.\n");
    exit(2);
}

if (!\is_file($config))
{
    \fwrite(\STDERR, "ERROR: Boson config '{$config}' not found.\n");
    exit(1);
}

// ---------------------------------------------------------------------------
// Load the master config and filter `target` down to the one we want
// ---------------------------------------------------------------------------
$json = \json_decode((string) \file_get_contents($config), true);

if (!\is_array($json) || !isset($json['target']) || !\is_array($json['target']))
{
    \fwrite(\STDERR, "ERROR: '{$config}' is not a valid Boson configuration (no target array).\n");
    exit(1);
}

$matches = ($all || $packOnly) ? $json['target'] : \array_values(
    \array_filter(
        $json['target'],
        static function (array $target) use ($type, $arch): bool {
            if (($target['type'] ?? null) !== $type)
            {
                return false;
            }

            // The PHAR target has no architecture; everything else must match arch.
            if ($type === 'phar')
            {
                return true;
            }

            return ($target['arch'] ?? null) === $arch;
        }
    )
);

if ($matches === [])
{
    $label = $type === 'phar' ? 'phar' : "{$type}/{$arch}";
    \fwrite(\STDERR, "ERROR: no '{$label}' target exists in {$config}.\n");
    exit(1);
}

$json['target'] = $matches;

// ---------------------------------------------------------------------------
// Use a custom (patched) SFX runtime when one is present in build/sfx/
// ---------------------------------------------------------------------------
// A stock Boson SFX appends the app PHAR after the executable's code-signature
// region, which makes the binary unsignable (see build/readme/01-macos-signing.md).
// Dropping a patched micro.sfx (built from the nikosdion/phpmicro `sibling-phar`
// fork via static-php-cli — scripts/fetch-sfx.sh downloads the CI-built ones)
// into build/sfx/<os>-<cpu>.standard.sfx makes the compiled binary able to load
// its payload from a sibling "<binary>.phar" file once make-macos-app.sh splits
// it, so the executable can be Developer-ID signed.
// The key is only injected when the file exists: Boson errors out on a dangling
// `sfx` path, and machines without a custom SFX must keep building normally.
$sfxCpuMap = ['arm64' => 'aarch64', 'amd64' => 'x86_64'];

foreach ($json['target'] as $i => $target)
{
    $targetType = (string) ($target['type'] ?? '');
    $sfxCpu     = $sfxCpuMap[$target['arch'] ?? ''] ?? null;

    if ($targetType === 'phar' || $targetType === '' || $sfxCpu === null)
    {
        continue;
    }

    $sfxRelative = "build/sfx/{$targetType}-{$sfxCpu}.standard.sfx";

    if (\is_file($root . '/' . $sfxRelative))
    {
        \fwrite(\STDOUT, "==> Using custom SFX runtime: {$sfxRelative}\n");

        $json['target'][$i]['sfx'] = $sfxRelative;
    }
}

// Pin the root so the filtered config can live in build/.temp without breaking
// the relative `directories`/`finder` paths (see the header note above).
$json['root'] = $root;

// ---------------------------------------------------------------------------
// Write the throwaway single-target config
// ---------------------------------------------------------------------------
$tempDir = $root . '/build/.temp';

if (!\is_dir($tempDir) && !\mkdir($tempDir, 0755, true) && !\is_dir($tempDir))
{
    \fwrite(\STDERR, "ERROR: could not create temp directory '{$tempDir}'.\n");
    exit(1);
}

$label    = $packOnly ? 'pack' : ($all ? 'all' : ($type === 'phar' ? 'phar' : "{$type}-{$arch}"));
$tempFile = $tempDir . '/boson.' . $label . '.json';

\file_put_contents(
    $tempFile,
    \json_encode($json, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE) . "\n"
);

// The compiler only regenerates its cached box config / entrypoint stub when they
// are older than the config it last saw; building target after target with the same
// cache can silently pack a stale inclusion list. Drop the cache before each compile
// (mirrors scripts/build-all.sh). The downloaded *.sfx runtimes are left in place.
// With --no-pack the PHAR (and the box config it came from) is the product of an earlier
// --pack-only run and is exactly what we want to compile, so it must survive.
foreach ($noPack ? [] : ['box.json', 'entrypoint.php', 'grafida.phar'] as $stale)
{
    if (\is_file($tempDir . '/' . $stale))
    {
        @\unlink($tempDir . '/' . $stale);
    }
}

// ---------------------------------------------------------------------------
// Compile just this target
// ---------------------------------------------------------------------------
// Pre-clean each target's output directory: Boson's own cleanup task chokes on
// leftovers it did not create — notably the Grafida.app bundle (which contains
// symlinks) assembled there by scripts/make-macos-app.sh after a previous
// build — and then aborts the whole compile.
foreach ($packOnly ? [] : $json['target'] as $target)
{
    $targetType = (string) ($target['type'] ?? '');

    if ($targetType === 'phar' || $targetType === '')
    {
        continue;
    }

    $archDir   = ($target['arch'] ?? '') === 'arm64' ? 'aarch64' : 'amd64';
    $outputDir = $root . "/build/{$targetType}/{$archDir}";

    if (\is_dir($outputDir))
    {
        \passthru(\sprintf('rm -rf %s', \escapeshellarg($outputDir)));
    }
}

$boson = $root . '/vendor/bin/boson';

if (!\is_file($boson))
{
    \fwrite(\STDERR, "ERROR: Boson compiler not found at '{$boson}'. Run 'composer install' with dev dependencies.\n");
    exit(1);
}

\fwrite(\STDOUT, ($packOnly ? "==> Packing the application PHAR" : "==> Compiling Boson target: {$label}") . "\n");

$command = \sprintf(
    '%s %s %s --config=%s%s',
    \escapeshellarg(\PHP_BINARY),
    \escapeshellarg($boson),
    $packOnly ? 'pack' : 'compile',
    \escapeshellarg($tempFile),
    $noPack ? ' --no-pack' : ''
);

\passthru($command, $exitCode);

@\unlink($tempFile);

exit($exitCode);
