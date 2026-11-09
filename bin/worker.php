<?php

/**
 * Grafida — Joomla content editing, untethered.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

/*
 * The stdio worker: Grafida's back-end as the process the native shell (grafida/shell) spawns,
 * answering its `boson://` requests in framed messages on stdin/stdout (grafida/grafida#82).
 *
 *   php bin/worker.php
 *
 * It builds the same container as index.php and serves the same FrontController; only the transport
 * differs. index.php (Boson) stays the shipped entry point until the switch-over release, so both
 * live side by side.
 *
 * Deliberately absent, because they belong to whichever process owns the window, i.e. the shell:
 * the webview pre-flight (StartupCheck), the macOS spell-check default (MacSpellCheck), and the
 * Windows console hiding (the shell spawns the worker with CREATE_NO_WINDOW). The native file
 * dialog is absent too, until Phase 2 replaces it with the shell's token flow (X-Shell-File-Path),
 * so `POST /api/dialog/*` answers 503 here, as it does for any dialog-less container.
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
use Grafida\Support\App;

// Before anything can print: a warning during bootstrap must not land ahead of the `ready` frame.
ini_set('display_errors', 'stderr');

$root = \dirname(__DIR__);

require $root . '/vendor/autoload.php';

$container = ContainerFactory::create([
    'static.provider' => new StaticFiles([
        $root . '/assets/private',
        $root . '/assets/public',
    ]),
]);

Worker::run($container->get(FrontController::class), new WorkerOptions(
    app: 'grafida',
    version: App::VERSION,
));
