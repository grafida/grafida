<?php

/**
 * Grafida — Joomla content editing, untethered.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Tests\Unit\Application;

use Grafida\Application\ContainerFactory;
use Grafida\Shell\StaticFiles;
use PHPUnit\Framework\TestCase;

/**
 * The composition root's parameters. joomla/di calls any callable value it is given as a factory,
 * and `static.provider` is a shell-php Handler, i.e. invokable: unwrapped, the container would call
 * `StaticFiles::__invoke($container)` instead of returning it (gh-84).
 */
final class ContainerFactoryTest extends TestCase
{
    public function testAnInvokableContainerParameterIsReturnedNotCalled(): void
    {
        $static    = new StaticFiles([]);
        $container = ContainerFactory::create(['static.provider' => $static, 'db.path' => ':memory:']);

        self::assertSame($static, $container->get('static.provider'));
    }
}
