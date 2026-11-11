<?php

/**
 * Grafida — Joomla content editing, untethered.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

/*
 * Packs seed-0.3.php and a Grafida 0.3 tree into seed.phar (gh-92). See tests/Upgrade/README.md.
 *
 *   php -d phar.readonly=0 tests/Upgrade/build-seed.php TREE OUT.phar
 *
 * TREE is a checkout of the `0.3` tag with `composer install --no-dev --no-scripts` run in it. The
 * PHAR runs on 0.3's own PHP: its sibling-phar SFX stub, copied and renamed so that it finds this
 * PHAR where it finds grafida.phar (macOS: MacOS/seed + Resources/seed.phar; Linux and Windows: the
 * stub and the PHAR side by side).
 */

if ($argc !== 3 || !is_dir($argv[1] . '/src') || !is_file($argv[1] . '/vendor/autoload.php')) {
    fwrite(STDERR, "usage: php -d phar.readonly=0 build-seed.php TREE OUT.phar\n  (TREE: a 0.3 checkout with composer install --no-dev run in it)\n");
    exit(64);
}

[, $tree, $out] = $argv;
$tree = rtrim((string) realpath($tree), '/');
$version = preg_match("/VERSION\\s*=\\s*'([^']+)'/", (string) file_get_contents($tree . '/src/Support/App.php'), $m) ? $m[1] : '?';

if ($version !== '0.3') {
    fwrite(STDERR, "build-seed: $tree is Grafida $version, not 0.3\n");
    exit(1);
}

@unlink($out);
$files = [];

foreach (['src', 'vendor', 'language', 'storage/migrations', 'docs'] as $dir) {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tree . '/' . $dir, FilesystemIterator::SKIP_DOTS));

    foreach ($iterator as $file) {
        /** @var SplFileInfo $file */
        if ($file->isFile()) {
            $files[substr($file->getPathname(), \strlen($tree) + 1)] = $file->getPathname();
        }
    }
}

$files['tests/Upgrade/seed-0.3.php'] = __DIR__ . '/seed-0.3.php';
ksort($files);

$phar = new Phar($out, 0, 'seed.phar');
$phar->startBuffering();
$phar->buildFromIterator(new ArrayIterator($files));
$phar->setStub("<?php\n/* Grafida 0.3 upgrade-test seed (tests/Upgrade). */\nPhar::mapPhar('seed.phar');\nrequire 'phar://seed.phar/tests/Upgrade/seed-0.3.php';\n__HALT_COMPILER();");
$phar->setSignatureAlgorithm(Phar::SHA256);
$phar->stopBuffering();

printf("%s: %d files from Grafida %s (%s)\n", $out, \count($files), $version, $tree);
