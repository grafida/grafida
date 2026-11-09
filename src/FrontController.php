<?php

/**
 * Grafida — Joomla content editing, untethered.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida;

use Grafida\Shell\Handler;
use Grafida\Shell\Request;
use Grafida\Shell\Response;
use Grafida\Application\Kernel;

/**
 * Entry point invoked for every `boson://` request. Delegates to the kernel,
 * which serves the API, static assets, or the SPA shell.
 *
 * It is a shell-php {@see Handler}, so the same object serves both entry
 * points: `index.php` (Boson, through `Grafida\Application\BosonBridge`) and
 * `bin/worker.php` (the stdio worker the native shell spawns).
 */
final class FrontController implements Handler
{
    public function __construct(private readonly Kernel $kernel) {}

    public function __invoke(Request $request): Response
    {
        return $this->kernel->handle($request);
    }
}
