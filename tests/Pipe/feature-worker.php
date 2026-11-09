<?php

/**
 * Grafida — Joomla content editing, untethered.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

/*
 * The worker half of WorkerPipeTest's differential run: bin/worker.php's loop around the Feature
 * suite's container (PipeApp) instead of the production one, so the only difference from the
 * in-process side is the pipe.
 */

declare(strict_types=1);

use Grafida\FrontController;
use Grafida\Shell\Worker;
use Grafida\Shell\WorkerOptions;
use Grafida\Support\App;
use Grafida\Tests\Pipe\PipeApp;

ini_set('display_errors', 'stderr');

require \dirname(__DIR__, 2) . '/vendor/autoload.php';

Worker::run(PipeApp::container()->get(FrontController::class), new WorkerOptions('grafida-pipe-test', App::VERSION));
