<?php

/**
 * Grafida — Joomla content editing, untethered.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Tests\Feature;

use Grafida\Application\Kernel;
use Grafida\Tests\Support\TestContainer;
use Grafida\Tests\Unit\Support\ArraySecretStore;
use PHPUnit\Framework\TestCase;

/**
 * Exercises the AI service CRUD routes end-to-end through the kernel.
 */
final class AiServiceRoutingTest extends TestCase
{
    // ------------------------------------------------------------------
    //  Helpers
    // ------------------------------------------------------------------

    private function kernelWithStore(?ArraySecretStore $store = null): Kernel
    {
        return TestContainer::create($store ?? false)->get(Kernel::class);
    }

    /** A kernel that uses no OS secret store (insecure-fallback path). */
    private function kernelInsecure(): Kernel
    {
        return $this->kernelWithStore(null);
    }

    /** A kernel that has an in-memory secret store (secure path). */
    private function kernelSecure(ArraySecretStore $store): Kernel
    {
        return $this->kernelWithStore($store);
    }

    /** @return array{0: int, 1: mixed} */
    private function call(Kernel $kernel, string $method, string $path, ?string $body = null): array
    {
        $request  = new \Grafida\Shell\Request($method, 'shell://app' . $path, [], $body ?? '');
        $response = $kernel->handle($request);

        return [(int) (string) $response->status, json_decode((string) $response->body, true)];
    }

    // ------------------------------------------------------------------
    //  Tests: insecure fallback (no OS keychain)
    // ------------------------------------------------------------------

    public function testCreateAndListInsecure(): void
    {
        $kernel = $this->kernelInsecure();

        [$status, $json] = $this->call($kernel, 'POST', '/api/ai/services', json_encode([
            'name'          => 'OpenAI',
            'provider'      => 'openai',
            'endpoint'      => 'https://api.openai.com',
            'model'         => 'gpt-4o',
            'key'           => 'sk-secret',
            'allowInsecure' => true,
        ]));

        self::assertSame(201, $status);
        self::assertTrue($json['ok']);
        self::assertIsInt($json['data']['id']);
        self::assertSame('OpenAI', $json['data']['name']);
        self::assertSame('openai', $json['data']['provider']);
        self::assertSame('gpt-4o', $json['data']['model']);

        // Key fields must NEVER appear in the response.
        self::assertArrayNotHasKey('secretRef', $json['data']);
        self::assertArrayNotHasKey('insecureKey', $json['data']);
        self::assertArrayNotHasKey('secret_ref', $json['data']);
        self::assertArrayNotHasKey('insecure_key', $json['data']);
    }

    public function testListReturnsNoKeys(): void
    {
        $kernel = $this->kernelInsecure();

        $this->call($kernel, 'POST', '/api/ai/services', json_encode([
            'name' => 'Test', 'provider' => 'custom', 'endpoint' => 'https://example.com',
            'model' => 'x', 'key' => 'secret', 'allowInsecure' => true,
        ]));

        [$status, $json] = $this->call($kernel, 'GET', '/api/ai/services');

        self::assertSame(200, $status);
        self::assertTrue($json['ok']);
        self::assertIsArray($json['data']);
        self::assertCount(1, $json['data']);

        $item = $json['data'][0];
        self::assertArrayNotHasKey('secretRef', $item);
        self::assertArrayNotHasKey('insecureKey', $item);
    }

    public function testGetSingleReturnsNoKey(): void
    {
        $kernel = $this->kernelInsecure();

        [, $created] = $this->call($kernel, 'POST', '/api/ai/services', json_encode([
            'name' => 'Test', 'provider' => 'custom', 'endpoint' => 'https://example.com',
            'model' => 'x', 'key' => 'secret', 'allowInsecure' => true,
        ]));

        $id = $created['data']['id'];

        [$status, $json] = $this->call($kernel, 'GET', '/api/ai/services/' . $id);

        self::assertSame(200, $status);
        self::assertTrue($json['ok']);
        self::assertSame($id, $json['data']['id']);
        self::assertArrayNotHasKey('secretRef', $json['data']);
        self::assertArrayNotHasKey('insecureKey', $json['data']);
    }

    public function testGetUnknownServiceIs404(): void
    {
        [$status, $json] = $this->call($this->kernelInsecure(), 'GET', '/api/ai/services/9999');

        self::assertSame(404, $status);
        self::assertFalse($json['ok']);
    }

    public function testSetDefaultMarksService(): void
    {
        $kernel = $this->kernelInsecure();

        [, $a] = $this->call($kernel, 'POST', '/api/ai/services', json_encode([
            'name' => 'A', 'provider' => 'p', 'endpoint' => 'https://a.example', 'model' => 'm',
            'allowInsecure' => true,
        ]));
        [, $b] = $this->call($kernel, 'POST', '/api/ai/services', json_encode([
            'name' => 'B', 'provider' => 'p', 'endpoint' => 'https://b.example', 'model' => 'm',
            'allowInsecure' => true,
        ]));

        $idA = $a['data']['id'];
        $idB = $b['data']['id'];

        // Set B as default.
        [$status, $json] = $this->call($kernel, 'POST', '/api/ai/services/' . $idB . '/default');

        self::assertSame(200, $status);
        self::assertTrue($json['ok']);
        self::assertTrue($json['data']['isDefault']);
        self::assertSame($idB, $json['data']['id']);

        // A must no longer be default.
        [, $aFetch] = $this->call($kernel, 'GET', '/api/ai/services/' . $idA);
        self::assertFalse($aFetch['data']['isDefault']);
    }

    public function testSetDefaultUnknownIs404(): void
    {
        [$status, $json] = $this->call($this->kernelInsecure(), 'POST', '/api/ai/services/9999/default');

        self::assertSame(404, $status);
        self::assertFalse($json['ok']);
    }

    public function testDefaultResolutionExplicitFlag(): void
    {
        $kernel = $this->kernelInsecure();

        [, $a] = $this->call($kernel, 'POST', '/api/ai/services', json_encode([
            'name' => 'A', 'provider' => 'p', 'endpoint' => 'https://a.example', 'model' => 'm',
            'allowInsecure' => true,
        ]));
        [, $b] = $this->call($kernel, 'POST', '/api/ai/services', json_encode([
            'name' => 'B', 'provider' => 'p', 'endpoint' => 'https://b.example', 'model' => 'm',
            'allowInsecure' => true,
        ]));

        $idB = $b['data']['id'];

        $this->call($kernel, 'POST', '/api/ai/services/' . $idB . '/default');

        [, $boot] = $this->call($kernel, 'GET', '/api/bootstrap');

        self::assertSame($idB, $boot['data']['aiDefaultServiceId']);
    }

    public function testDefaultResolutionLowestIdFallback(): void
    {
        $kernel = $this->kernelInsecure();

        [, $a] = $this->call($kernel, 'POST', '/api/ai/services', json_encode([
            'name' => 'A', 'provider' => 'p', 'endpoint' => 'https://a.example', 'model' => 'm',
            'allowInsecure' => true,
        ]));
        [, $b] = $this->call($kernel, 'POST', '/api/ai/services', json_encode([
            'name' => 'B', 'provider' => 'p', 'endpoint' => 'https://b.example', 'model' => 'm',
            'allowInsecure' => true,
        ]));

        // Neither has is_default = 1 — lowest id wins.
        $idA = $a['data']['id'];
        $idB = $b['data']['id'];
        self::assertLessThan($idB, $idA);

        [, $boot] = $this->call($kernel, 'GET', '/api/bootstrap');

        // No explicit default set — aiDefaultServiceId points to lowest id.
        self::assertSame($idA, $boot['data']['aiDefaultServiceId']);
    }

    public function testDefaultResolutionNullWhenEmpty(): void
    {
        $kernel = $this->kernelInsecure();

        [, $boot] = $this->call($kernel, 'GET', '/api/bootstrap');

        self::assertNull($boot['data']['aiDefaultServiceId']);
        self::assertSame([], $boot['data']['aiServices']);
    }

    public function testUpdateWithoutNewKeyKeepsExistingKey(): void
    {
        $kernel = $this->kernelInsecure();

        [, $created] = $this->call($kernel, 'POST', '/api/ai/services', json_encode([
            'name' => 'Test', 'provider' => 'p', 'endpoint' => 'https://x.example',
            'model' => 'm', 'key' => 'original-key', 'allowInsecure' => true,
        ]));

        $id = $created['data']['id'];

        [$status, $json] = $this->call($kernel, 'PATCH', '/api/ai/services/' . $id, json_encode([
            'name' => 'Updated Name',
        ]));

        self::assertSame(200, $status);
        self::assertTrue($json['ok']);
        self::assertSame('Updated Name', $json['data']['name']);
        self::assertSame('p', $json['data']['provider']);
        self::assertArrayNotHasKey('insecureKey', $json['data']);
    }

    public function testUpdateWithNewKeyReplacesKey(): void
    {
        $kernel = $this->kernelInsecure();

        [, $created] = $this->call($kernel, 'POST', '/api/ai/services', json_encode([
            'name' => 'Test', 'provider' => 'p', 'endpoint' => 'https://x.example',
            'model' => 'm', 'key' => 'original-key', 'allowInsecure' => true,
        ]));

        $id = $created['data']['id'];

        [$status, $json] = $this->call($kernel, 'PATCH', '/api/ai/services/' . $id, json_encode([
            'key' => 'new-key', 'allowInsecure' => true,
        ]));

        self::assertSame(200, $status);
        self::assertTrue($json['ok']);
        self::assertArrayNotHasKey('insecureKey', $json['data']);
    }

    public function testUpdateUnknownIs404(): void
    {
        [$status, $json] = $this->call($this->kernelInsecure(), 'PATCH', '/api/ai/services/9999', json_encode([
            'name' => 'X',
        ]));

        self::assertSame(404, $status);
        self::assertFalse($json['ok']);
    }

    public function testDeleteRemovesService(): void
    {
        $kernel = $this->kernelInsecure();

        [, $created] = $this->call($kernel, 'POST', '/api/ai/services', json_encode([
            'name' => 'ToDelete', 'provider' => 'p', 'endpoint' => 'https://x.example',
            'model' => 'm', 'allowInsecure' => true,
        ]));

        $id = $created['data']['id'];

        [$status, $json] = $this->call($kernel, 'DELETE', '/api/ai/services/' . $id);

        self::assertSame(200, $status);
        self::assertTrue($json['ok']);

        [, $list] = $this->call($kernel, 'GET', '/api/ai/services');
        self::assertCount(0, $list['data']);
    }

    public function testDeleteUnknownIs404(): void
    {
        [$status, $json] = $this->call($this->kernelInsecure(), 'DELETE', '/api/ai/services/9999');

        self::assertSame(404, $status);
        self::assertFalse($json['ok']);
    }

    // ------------------------------------------------------------------
    //  Tests: in-memory secret store (secure path)
    // ------------------------------------------------------------------

    public function testCreateStoresKeyInSecureStore(): void
    {
        $store  = new ArraySecretStore();
        $kernel = $this->kernelSecure($store);

        [, $created] = $this->call($kernel, 'POST', '/api/ai/services', json_encode([
            'name' => 'Secure', 'provider' => 'openai', 'endpoint' => 'https://api.openai.com',
            'model' => 'gpt-4o', 'key' => 'sk-my-secret',
        ]));

        self::assertTrue($created['ok']);
        $id = $created['data']['id'];

        // Key must be in the store under the predictable reference.
        $expectedRef = 'grafida.ai_service.' . $id;
        self::assertArrayHasKey($expectedRef, $store->secrets);
        self::assertSame('sk-my-secret', $store->secrets[$expectedRef]);

        // Key must NOT appear in the response.
        self::assertArrayNotHasKey('secretRef', $created['data']);
        self::assertArrayNotHasKey('insecureKey', $created['data']);
    }

    public function testDeleteRemovesKeyFromSecureStore(): void
    {
        $store  = new ArraySecretStore();
        $kernel = $this->kernelSecure($store);

        [, $created] = $this->call($kernel, 'POST', '/api/ai/services', json_encode([
            'name' => 'Secure', 'provider' => 'openai', 'endpoint' => 'https://api.openai.com',
            'model' => 'gpt-4o', 'key' => 'sk-to-delete',
        ]));

        $id = $created['data']['id'];
        $ref = 'grafida.ai_service.' . $id;
        self::assertArrayHasKey($ref, $store->secrets);

        $this->call($kernel, 'DELETE', '/api/ai/services/' . $id);

        self::assertArrayNotHasKey($ref, $store->secrets);
    }

    public function testUpdateRotatesKeyInSecureStore(): void
    {
        $store  = new ArraySecretStore();
        $kernel = $this->kernelSecure($store);

        [, $created] = $this->call($kernel, 'POST', '/api/ai/services', json_encode([
            'name' => 'Secure', 'provider' => 'openai', 'endpoint' => 'https://api.openai.com',
            'model' => 'gpt-4o', 'key' => 'sk-original',
        ]));

        $id  = $created['data']['id'];
        $ref = 'grafida.ai_service.' . $id;
        self::assertSame('sk-original', $store->secrets[$ref] ?? null);

        $this->call($kernel, 'PATCH', '/api/ai/services/' . $id, json_encode([
            'key' => 'sk-rotated',
        ]));

        self::assertSame('sk-rotated', $store->secrets[$ref] ?? null);
    }

    public function testUpdateWithoutKeyLeavesStoreUntouched(): void
    {
        $store  = new ArraySecretStore();
        $kernel = $this->kernelSecure($store);

        [, $created] = $this->call($kernel, 'POST', '/api/ai/services', json_encode([
            'name' => 'Secure', 'provider' => 'openai', 'endpoint' => 'https://api.openai.com',
            'model' => 'gpt-4o', 'key' => 'sk-unchanged',
        ]));

        $id      = $created['data']['id'];
        $ref     = 'grafida.ai_service.' . $id;
        $before  = $store->secrets[$ref] ?? null;

        $this->call($kernel, 'PATCH', '/api/ai/services/' . $id, json_encode([
            'name' => 'Renamed',
        ]));

        self::assertSame($before, $store->secrets[$ref] ?? null);
    }

    // ------------------------------------------------------------------
    //  Bootstrap payload
    // ------------------------------------------------------------------

    public function testBootstrapIncludesAiPayload(): void
    {
        $kernel = $this->kernelInsecure();

        // Create one service first.
        $this->call($kernel, 'POST', '/api/ai/services', json_encode([
            'name' => 'Boot', 'provider' => 'openai', 'endpoint' => 'https://api.openai.com',
            'model' => 'gpt-4o', 'allowInsecure' => true,
        ]));

        [, $boot] = $this->call($kernel, 'GET', '/api/bootstrap');

        self::assertArrayHasKey('aiServices', $boot['data']);
        self::assertArrayHasKey('aiDefaultServiceId', $boot['data']);
        self::assertArrayHasKey('aiProviders', $boot['data']);
        self::assertArrayHasKey('secureStoreAi', $boot['data']);
        self::assertIsArray($boot['data']['aiProviders']);
        self::assertCount(1, $boot['data']['aiServices']);

        $item = $boot['data']['aiServices'][0];
        self::assertArrayNotHasKey('secretRef', $item);
        self::assertArrayNotHasKey('insecureKey', $item);
    }

    public function testBootstrapSecureStoreAiReflectsAvailability(): void
    {
        // kernelInsecure uses false → null store → secureStoreAi = false
        $kernelInsecure = $this->kernelInsecure();
        [, $boot] = $this->call($kernelInsecure, 'GET', '/api/bootstrap');
        self::assertFalse($boot['data']['secureStoreAi']);

        // kernelSecure uses an ArraySecretStore → secureStoreAi = true
        $kernelSecure = $this->kernelSecure(new ArraySecretStore());
        [, $boot2] = $this->call($kernelSecure, 'GET', '/api/bootstrap');
        self::assertTrue($boot2['data']['secureStoreAi']);
    }
}
