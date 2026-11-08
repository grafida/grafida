<?php

/**
 * Grafida — Joomla content editing, untethered.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

/*
 * Binary-patch the prebuilt Linux libboson so a POST to the boson:// scheme does not abort the
 * process (gh-79).
 *
 * The bundled saucer reads a scheme request body with
 *     g_input_stream_read_bytes(stream, G_MAXSSIZE, NULL, NULL)
 * and g_input_stream_read_bytes() g_malloc()s `count` up front, so GLib dies with "failed to
 * allocate 9223372036854775807 bytes" on the first POST that carries a body — saving a site,
 * switching the theme, anything that writes. Upstream fixed it in saucer/saucer#57 (827e38bd,
 * 2025-07-12) but boson-php/saucer 0.19.5 still ships a library built before that, and Boson is
 * no longer maintained, so no rebuild is coming.
 *
 * The patch rewrites only that one immediate operand to 64 MiB. It stays a single read(), which
 * is enough because every internal API POST is one JSON string (app.js `api()` stringifies the
 * body) — a single in-memory data element, which WebKitGTK's form-data stream hands back whole.
 * 64 MiB is far above any body the SPA sends and PHP's own memory_limit would stop a larger one
 * first. A body above it would be truncated (a JSON error), not a crash.
 *
 * Every library is pinned by SHA-256, before AND after: an already-patched file is left alone, and
 * anything else — a Boson update, a different build — FAILS the build rather than patching bytes
 * we have not looked at, or silently shipping a library that crashes. If that happens, disassemble
 * saucer::scheme::request::content() in the new library: if it reads in a loop, the bug is fixed
 * and the entry below can go; otherwise re-derive the offset and hashes.
 *
 * Usage (normally called by compile-target.php after a successful compile):
 *   php build/tasks/patch-libboson.php <path/to/libboson-linux-*.so> [...]
 */

/**
 * @var array<string, array{offset:int, original:string, patched:string, sha256:string, sha256Patched:string}>
 */
const GRAFIDA_LIBBOSON_PATCHES = [
    // x86_64: movabs $0x7fffffffffffffff,%rax  →  movabs $0x4000000,%rax
    'libboson-linux-x86_64.so'  => [
        'offset'        => 0x297a8a,
        'original'      => '48b8ffffffffffffff7f',
        'patched'       => '48b80000000400000000',
        'sha256'        => '4bf7374bc1812fd2dbccd21313771c109516d1eee1f5e3f2583b8f15bd36672d',
        'sha256Patched' => 'c558f9cbc7adf1651f6ee2ff28a9b8245fc1e335fd10e9b24e8c1cd905194647',
    ],
    // aarch64: mov x1, #0x7fffffffffffffff (movn)  →  mov x1, #0x4000000 (movz x1, #0x400, lsl #16)
    'libboson-linux-aarch64.so' => [
        'offset'        => 0x29c364,
        'original'      => '0100f092',
        'patched'       => '0180a0d2',
        'sha256'        => 'fe32cffa20bf3402eab3f25383d8b81852ff04c20fdc26e039ad652a2276a6ab',
        'sha256Patched' => '0d9bfb9b67430ddade5464a21eee93acf02d9d45346ba0e891a748192420bd9a',
    ],
];

/**
 * Patch one library in place. Returns an error message, or null on success.
 */
function grafidaPatchLibboson(string $file): ?string
{
    $name  = \basename($file);
    $patch = GRAFIDA_LIBBOSON_PATCHES[$name] ?? null;

    if ($patch === null)
    {
        return "no gh-79 patch is defined for '{$name}'";
    }

    if (!\is_file($file))
    {
        return "'{$file}' does not exist";
    }

    $hash = \hash_file('sha256', $file);

    if ($hash === $patch['sha256Patched'])
    {
        \fwrite(\STDOUT, "    {$name}: already patched (gh-79)\n");

        return null;
    }

    if ($hash !== $patch['sha256'])
    {
        return "'{$file}' is not the library the gh-79 patch was written for (sha256 {$hash}). "
            . 'Re-check saucer::scheme::request::content() before shipping it — see build/tasks/patch-libboson.php.';
    }

    $data     = (string) \file_get_contents($file);
    $original = (string) \hex2bin($patch['original']);
    $patched  = (string) \hex2bin($patch['patched']);

    if (\substr($data, $patch['offset'], \strlen($original)) !== $original)
    {
        return "unexpected bytes at offset 0x" . \dechex($patch['offset']) . " in '{$file}'";
    }

    $data = \substr_replace($data, $patched, $patch['offset'], \strlen($patched));

    if (\hash('sha256', $data) !== $patch['sha256Patched'])
    {
        return "patching '{$file}' did not produce the expected result";
    }

    if (\file_put_contents($file, $data) !== \strlen($data))
    {
        return "could not write '{$file}'";
    }

    \fwrite(\STDOUT, "    {$name}: patched the scheme request body read (gh-79)\n");

    return null;
}

if (\realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__)
{
    $files = \array_slice($argv, 1);

    if ($files === [])
    {
        \fwrite(\STDERR, "Usage: php build/tasks/patch-libboson.php <libboson-linux-*.so> [...]\n");
        exit(1);
    }

    foreach ($files as $file)
    {
        if (($error = grafidaPatchLibboson($file)) !== null)
        {
            \fwrite(\STDERR, "ERROR: {$error}\n");
            exit(1);
        }
    }
}
