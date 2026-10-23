<?php

/**
 * Grafida — Joomla content editing, untethered.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Tests\Unit;

use Grafida\Http\HttpResponse;
use Grafida\Joomla\ApiClient;
use Grafida\Site\SecureStoreUnavailableException;
use Grafida\Site\SiteRepository;
use Grafida\Site\SiteService;
use Grafida\Tests\Support\TestDatabase;
use Grafida\Tests\Unit\Support\ArraySecretStore;
use Grafida\Tests\Unit\Support\FakeTransport;
use Joomla\Database\DatabaseInterface;

final class SiteServiceTest extends TestCase
{
    private DatabaseInterface $db;

    protected function setUp(): void
    {
        $this->db = TestDatabase::memory();
    }

    private function transport(): FakeTransport
    {
        $ok = new HttpResponse(200, '{"data":[{"type":"levels","id":"1","attributes":{"title":"Public"}}]}', ['Content-Type' => 'application/vnd.api+json']);

        return (new FakeTransport())
            ->on('https://example.com/api/index.php/v1/users/levels', $ok);
    }

    public function testCreateStoresTokenInSecureStore(): void
    {
        $store   = new ArraySecretStore();
        $service = new SiteService(new SiteRepository($this->db), new ApiClient($this->transport()), $store);

        $site = $service->create('My Site', 'https://example.com', 'secret-token');

        self::assertNotNull($site->id);
        self::assertSame('https://example.com/api/index.php', $site->apiBase);
        self::assertFalse($site->isInsecure());
        self::assertContains('secret-token', array_values($store->secrets));
        self::assertSame('secret-token', $service->tokenFor($site));
    }

    public function testCreateWithoutSecureStoreRequiresOptIn(): void
    {
        $service = new SiteService(new SiteRepository($this->db), new ApiClient($this->transport()), null);

        $this->expectException(SecureStoreUnavailableException::class);
        $service->create('My Site', 'https://example.com', 'secret-token');
    }

    public function testCreateInsecureFallbackStoresPlaintext(): void
    {
        $repo    = new SiteRepository($this->db);
        $service = new SiteService($repo, new ApiClient($this->transport()), null);

        $site = $service->create('My Site', 'https://example.com', 'secret-token', allowInsecure: true);

        self::assertTrue($site->isInsecure());
        self::assertSame('secret-token', $service->tokenFor($site));
        self::assertSame('secret-token', $repo->insecureToken((int) $site->id));
    }

    public function testDeleteRemovesSecret(): void
    {
        $store   = new ArraySecretStore();
        $service = new SiteService(new SiteRepository($this->db), new ApiClient($this->transport()), $store);

        $site = $service->create('My Site', 'https://example.com', 'secret-token');
        $service->delete((int) $site->id);

        self::assertSame([], $store->secrets);
        self::assertNull($service->find((int) $site->id));
    }
}
