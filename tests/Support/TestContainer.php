<?php

/**
 * Grafida — Joomla content editing, untethered.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Tests\Support;

use Grafida\Application\Container;
use Grafida\Application\ContainerFactory;
use Grafida\Secret\SecretStore;
use Grafida\Shell\StaticFiles;

/** Builds a fully-wired application container backed by an in-memory database, for Feature tests. */
final class TestContainer
{
    public static function create(
        SecretStore|false|null $store = null,
    ): Container {
        return ContainerFactory::create([
            'db.path'         => ':memory:',
            'base.path'       => \dirname(__DIR__, 2),
            'static.provider' => new StaticFiles([]),
            'secret.store'    => $store,
        ]);
    }
}
