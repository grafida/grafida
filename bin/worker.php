<?php

/**
 * Grafida — Joomla content editing, untethered.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

/*
 * The stdio worker: Grafida's back-end as the process the native shell (grafida/shell) spawns,
 * answering its `boson://` requests in framed messages on stdin/stdout (grafida/grafida#82). It is
 * Grafida's only entry point (gh-92): the packaged SFX stub runs it from its PHAR, and dev mode runs
 * it with the system PHP.
 *
 *   php bin/worker.php
 *
 * Deliberately absent, because they belong to the process that owns the window, i.e. the shell:
 * the webview pre-flight (a too-old macOS, a missing WebKitGTK or WebView2), the macOS spell-check
 * default, and anything to do with a console window. There is no native dialog here either: the
 * shell opens its own, hands the SPA a token, and forwards the picked path as X-Shell-File-Path
 * (Grafida\Http\ShellFile, gh-85). A dialog request without that header answers 503.
 *
 * If the app cannot be built (a database that cannot be opened, say), the worker never sends
 * `ready`: it writes FailureReporter's plain sentence to stderr, then the stack trace cut to a few
 * lines, and exits 69. The shell's failure alert shows the last 40 lines of the worker's stderr,
 * so the sentence must not be pushed out of them by a trace (a chained one runs to ~70 lines).
 *
 * Nothing may be printed to stdout, which carries the frame stream. Worker::run() moves
 * display_errors to stderr and diverts any handler output there, tagged [stray-output].
 */

declare(strict_types=1);

use Grafida\Application\ContainerFactory;
use Grafida\FrontController;
use Grafida\Shell\StaticFiles;
use Grafida\Shell\Worker;
use Grafida\Shell\WorkerOptions;
use Grafida\Startup\FailureReporter;
use Grafida\Support\App;
use Grafida\Support\Paths;

// Before anything can print: a warning during bootstrap must not land ahead of the `ready` frame.
ini_set('display_errors', 'stderr');

$root = \dirname(__DIR__);

require $root . '/vendor/autoload.php';

try {
    $container = ContainerFactory::create([
        'static.provider' => new StaticFiles([
            $root . '/assets/private',
            $root . '/assets/public',
        ]),
    ]);

    $front = $container->get(FrontController::class);
} catch (\Throwable $e) {
    $trace = explode("\n", $e->getTraceAsString());

    fwrite(\STDERR, FailureReporter::describe($e, Paths::databaseFile()) . "\n\n"
        . implode("\n", array_slice($trace, 0, 20))
        . (count($trace) > 20 ? "\n… (" . (count($trace) - 20) . ' more frames)' : '') . "\n");

    exit(FailureReporter::EXIT_FAILED);
}

Worker::run($front, new WorkerOptions(
    app: 'grafida',
    version: App::VERSION,
));
