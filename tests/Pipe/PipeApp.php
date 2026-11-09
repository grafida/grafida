<?php

/**
 * Grafida — Joomla content editing, untethered.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Tests\Pipe;

use Grafida\Application\Container;
use Grafida\Application\ContainerFactory;
use Grafida\Shell\StaticFiles;
use Grafida\Tests\Unit\Support\ArraySecretStore;
use Joomla\Database\DatabaseInterface;

/**
 * The application both sides of WorkerPipeTest's differential run: the Feature suite's container
 * (in-memory database, no dialog), plus the real asset roots so static files cross the pipe too,
 * an in-memory secret store so nothing reaches the OS keyring, and one seeded site, as the Feature
 * tests seed one for drafts and media.
 *
 * Built once in the test process and once inside `feature-worker.php`; the two must be
 * indistinguishable, so everything here is deterministic.
 */
final class PipeApp
{
    public const int SITE_ID = 1;

    private function __construct()
    {
    }

    public static function container(): Container
    {
        $root      = \dirname(__DIR__, 2);
        $container = ContainerFactory::create([
            'db.path'         => ':memory:',
            'base.path'       => $root,
            'static.provider' => new StaticFiles([$root . '/assets/private', $root . '/assets/public']),
            'dialog'          => null,
            'secret.store'    => new ArraySecretStore(),
        ]);

        $db    = $container->get(DatabaseInterface::class);
        $title = 'Pipe';
        $url   = 'https://example.test';
        $now   = '2026-01-01 00:00:00';
        $query = $db->createQuery()
            ->insert($db->quoteName('sites'))
            ->columns($db->quoteName(['title', 'base_url', 'created_at', 'updated_at']))
            ->values(':t, :u, :c, :m')
            ->bind(':t', $title)
            ->bind(':u', $url)
            ->bind(':c', $now)
            ->bind(':m', $now);
        $db->setQuery($query)->execute();

        return $container;
    }
}
