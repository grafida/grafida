<?php

/**
 * Grafida — Joomla content editing, untethered.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Tests\Unit\Application;

use Boson\Component\Http\Request as BosonRequest;
use Grafida\Application\BosonBridge;
use Grafida\Application\ContainerFactory;
use Grafida\Shell\Handler;
use Grafida\Shell\Request;
use Grafida\Shell\Response;
use Grafida\Shell\StaticFiles;
use PHPUnit\Framework\TestCase;

/**
 * The adapter that keeps the app shipping on Boson while everything behind index.php speaks
 * shell-php's types (gh-84).
 */
final class BosonBridgeTest extends TestCase
{
    public function testRequestCarriesMethodUrlEveryHeaderValueAndBody(): void
    {
        $request = BosonBridge::request(new BosonRequest(
            'POST',
            'boson://app/api/sites/1/media?path=a%20b%2Fc',
            ['Content-Type' => 'application/json', 'X-Multi' => ['one', 'two']],
            '{"a":1}',
        ));

        self::assertSame('POST', $request->method);
        self::assertSame('/api/sites/1/media', $request->path());
        self::assertSame('a b/c', $request->query('path'));
        self::assertSame('application/json', $request->header('content-type'));
        self::assertSame(['one', 'two'], $request->headers->all('x-multi'));
        self::assertSame('{"a":1}', $request->body);
    }

    /** The kernel only ever saw Boson's parsed URL; the bridge passes that same form on. */
    public function testUrlIsBosonsNormalisedForm(): void
    {
        $request = BosonBridge::request(new BosonRequest('GET', 'boson://app/api//sites'));

        self::assertSame('/api/sites', $request->path());
    }

    public function testResponseKeepsBodyStatusAndHeaders(): void
    {
        $boson = BosonBridge::response(new Response('{"ok":true}', 409, [
            'Content-Type'  => 'application/json; charset=utf-8',
            'Cache-Control' => 'no-store',
            'X-Multi'       => ['one', 'two'],
        ]));

        self::assertSame('{"ok":true}', (string) $boson->body);
        self::assertSame(409, $boson->status->code);

        $pairs = [];
        foreach ($boson->headers as $name => $value) {
            $pairs[] = [strtolower((string) $name), (string) $value];
        }

        self::assertContains(['content-type', 'application/json; charset=utf-8'], $pairs);
        self::assertContains(['cache-control', 'no-store'], $pairs);
        self::assertContains(['x-multi', 'one'], $pairs);
        self::assertContains(['x-multi', 'two'], $pairs);
    }

    public function testHandleRunsAShellHandler(): void
    {
        $handler = new class implements Handler {
            public function __invoke(Request $request): Response
            {
                return Response::text($request->method . ' ' . $request->path(), 201);
            }
        };

        $boson = BosonBridge::handle($handler, new BosonRequest('PATCH', 'boson://app/api/drafts/3'));

        self::assertSame('PATCH /api/drafts/3', (string) $boson->body);
        self::assertSame(201, $boson->status->code);
    }

    /**
     * joomla/di calls a callable value as a factory. StaticFiles is invokable (a shell-php Handler),
     * so the factory must store parameters as values or the container calls StaticFiles($container).
     */
    public function testAnInvokableContainerParameterIsReturnedNotCalled(): void
    {
        $static    = new StaticFiles([]);
        $container = ContainerFactory::create(['static.provider' => $static, 'db.path' => ':memory:']);

        self::assertSame($static, $container->get('static.provider'));
    }
}
