<?php

/**
 * Grafida — Joomla content editing, untethered.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Tests\Unit\Ai;

use Grafida\Ai\AiProxy;
use Grafida\Ai\AiProxyException;
use Grafida\Ai\AiService;
use Grafida\Ai\AiServiceManager;
use Grafida\Ai\AiServiceRepository;
use Grafida\Ai\Defaults;
use Grafida\Http\HttpResponse;
use Grafida\Tests\Support\TestDatabase;
use Grafida\Tests\Unit\Support\FakeTransport;
use Grafida\Tests\Unit\TestCase;
use Joomla\Database\DatabaseInterface;

/**
 * Unit tests for AiProxy — uses an in-memory DB and a FakeTransport.
 */
final class AiProxyTest extends TestCase
{
    private DatabaseInterface $db;

    protected function setUp(): void
    {
        $this->db = TestDatabase::memory();
    }

    // ------------------------------------------------------------------
    //  Helpers
    // ------------------------------------------------------------------

    private function manager(): AiServiceManager
    {
        return new AiServiceManager(new AiServiceRepository($this->db), null);
    }

    private function proxy(FakeTransport $transport, ?AiServiceManager $manager = null): AiProxy
    {
        return new AiProxy($manager ?? $this->manager(), new Defaults(), $transport);
    }

    /** Creates a service and returns its id. */
    private function createService(string $provider, string $endpoint, string $model = 'gpt-4o'): int
    {
        $service = $this->manager()->create([
            'name'          => 'Test',
            'provider'      => $provider,
            'endpoint'      => $endpoint,
            'model'         => $model,
            'key'           => 'test-key',
            'allowInsecure' => true,
        ]);

        \assert($service->id !== null);

        return $service->id;
    }

    // ------------------------------------------------------------------
    //  Tests: allowed host
    // ------------------------------------------------------------------

    public function testForwardsRequestToAllowedHost(): void
    {
        $transport = new FakeTransport(new HttpResponse(200, '{"result":"ok"}'));
        $serviceId = $this->createService('custom', 'https://api.example.com/v1');
        $proxy     = $this->proxy($transport);

        $result = $proxy->forward(
            serviceId: $serviceId,
            url: 'https://api.example.com/v1/chat/completions',
            method: 'POST',
            headers: ['Content-Type' => 'application/json', 'Authorization' => 'Bearer sk-test'],
            body: '{"model":"x"}',
        );

        self::assertSame(200, $result['status']);
        self::assertSame('{"result":"ok"}', $result['body']);

        // Verify the transport received the request.
        self::assertCount(1, $transport->requests);
        self::assertSame('POST', $transport->requests[0]['method']);
        self::assertSame('https://api.example.com/v1/chat/completions', $transport->requests[0]['url']);
        self::assertSame('Bearer sk-test', $transport->requests[0]['headers']['Authorization']);
    }

    public function testRejectsDisallowedHost(): void
    {
        $transport = new FakeTransport(new HttpResponse(200, '{}'));
        $serviceId = $this->createService('custom', 'https://api.example.com/v1');
        $proxy     = $this->proxy($transport);

        $this->expectException(AiProxyException::class);

        $proxy->forward(
            serviceId: $serviceId,
            url: 'https://evil.attacker.com/v1/chat/completions',
            method: 'POST',
            headers: [],
            body: '',
        );
    }

    public function testRejectsDisallowedHostWithCorrect403Status(): void
    {
        $transport = new FakeTransport(new HttpResponse(200, '{}'));
        $serviceId = $this->createService('custom', 'https://api.example.com/v1');
        $proxy     = $this->proxy($transport);

        try {
            $proxy->forward(
                serviceId: $serviceId,
                url: 'https://evil.attacker.com/steal',
                method: 'POST',
                headers: [],
                body: '',
            );
            self::fail('Expected AiProxyException was not thrown.');
        } catch (AiProxyException $e) {
            self::assertSame(403, $e->httpStatus);
        }
    }

    public function testRejectsAnEmptyMethodAsBadInputWithoutContactingTheTransport(): void
    {
        $transport = new FakeTransport(new HttpResponse(200, '{}'));
        $serviceId = $this->createService('custom', 'https://api.example.com/v1');
        $proxy     = $this->proxy($transport);

        try {
            $proxy->forward(
                serviceId: $serviceId,
                url: 'https://api.example.com/v1/chat/completions',
                method: '',
                headers: [],
                body: '',
            );
            self::fail('Expected AiProxyException was not thrown.');
        } catch (AiProxyException $e) {
            // 400, not the 500 an InvalidArgumentException from the transport would become.
            self::assertSame(400, $e->httpStatus);
        }

        self::assertCount(0, $transport->requests);
    }

    public function testHostMatchIsCaseInsensitive(): void
    {
        $transport = new FakeTransport(new HttpResponse(200, 'ok'));
        $serviceId = $this->createService('custom', 'https://API.EXAMPLE.COM/v1');
        $proxy     = $this->proxy($transport);

        // Should NOT throw even though cases differ.
        $result = $proxy->forward(
            serviceId: $serviceId,
            url: 'https://api.example.com/v1/chat',
            method: 'POST',
            headers: [],
            body: '',
        );

        self::assertSame(200, $result['status']);
    }

    public function testRejectsUnknownServiceId(): void
    {
        $transport = new FakeTransport();
        $proxy     = $this->proxy($transport);

        $this->expectException(AiProxyException::class);

        $proxy->forward(9999, 'https://api.example.com/', 'POST', [], '');
    }

    public function testRejectsUnknownServiceWith404Status(): void
    {
        $transport = new FakeTransport();
        $proxy     = $this->proxy($transport);

        try {
            $proxy->forward(9999, 'https://api.example.com/', 'POST', [], '');
            self::fail('Expected AiProxyException was not thrown.');
        } catch (AiProxyException $e) {
            self::assertSame(404, $e->httpStatus);
        }
    }

    public function testServiceEndpointDeterminesAllowedHost(): void
    {
        // Verify that the configured service endpoint determines which host is allowed.
        $transport = new FakeTransport(new HttpResponse(200, 'preset-ok'));
        $serviceId = $this->createService('openai', 'https://api.openai.com/v1');
        $proxy     = $this->proxy($transport);

        // Forward to the configured endpoint's host — should succeed.
        $result = $proxy->forward(
            serviceId: $serviceId,
            url: 'https://api.openai.com/v1/chat/completions',
            method: 'POST',
            headers: [],
            body: '',
        );

        self::assertSame(200, $result['status']);
        self::assertCount(1, $transport->requests);
    }

    public function testPassesRawBodyToTransport(): void
    {
        $transport = new FakeTransport(new HttpResponse(200, ''));
        $serviceId = $this->createService('custom', 'https://llm.example.io');
        $proxy     = $this->proxy($transport);

        $payload = '{"model":"my-model","messages":[{"role":"user","content":"Hi"}]}';

        $proxy->forward($serviceId, 'https://llm.example.io/chat', 'POST', [], $payload);

        self::assertSame($payload, $transport->requests[0]['body']);
    }

    public function testEmptyBodySendsNullToTransport(): void
    {
        $transport = new FakeTransport(new HttpResponse(200, ''));
        $serviceId = $this->createService('custom', 'https://llm.example.io');
        $proxy     = $this->proxy($transport);

        $proxy->forward($serviceId, 'https://llm.example.io/chat', 'GET', [], '');

        self::assertNull($transport->requests[0]['body']);
    }
}
