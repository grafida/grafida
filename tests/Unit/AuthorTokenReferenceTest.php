<?php

/**
 * Grafida — Joomla content editing, untethered.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Tests\Unit;

use Grafida\Joomla\ApiClient;
use Grafida\Joomla\ApiException;
use Grafida\Reference\ReferenceRepository;
use Grafida\Reference\ReferenceService;
use Grafida\Site\Site;
use Grafida\Site\SiteRepository;
use Grafida\Site\SiteService;
use Grafida\Tests\Support\TestDatabase;
use Grafida\Tests\Unit\Support\JoomlaSiteTransport;
use Joomla\Database\DatabaseInterface;

/**
 * A token does not have to belong to a Super User: Joomla can issue API tokens
 * to a dedicated group, and that user's ordinary permissions still govern every
 * route. Every other test in the suite answers as a user who may do anything;
 * these answer as an Author — `core.admin` and com_templates' `core.manage`
 * withheld — and pin that connecting and reading reference data degrade rather
 * than throw.
 */
final class AuthorTokenReferenceTest extends TestCase
{
    private DatabaseInterface $db;
    private ReferenceRepository $repository;

    protected function setUp(): void
    {
        $this->db         = TestDatabase::memory();
        $this->repository = new ReferenceRepository($this->db);
    }

    private function seedSite(): Site
    {
        $now = gmdate('Y-m-d H:i:s');
        $pdo = TestDatabase::connection($this->db);
        $pdo->prepare(
            'INSERT INTO sites (title, base_url, api_base, insecure_token, created_at, updated_at)'
            . ' VALUES (?, ?, ?, ?, ?, ?)'
        )->execute(['Site', 'https://example.com', 'https://example.com/index.php/api', 'tok', $now, $now]);

        return new Site(
            (int) $pdo->lastInsertId(),
            'Site',
            'https://example.com',
            'https://example.com/index.php/api',
            null,
            true,
        );
    }

    private function references(JoomlaSiteTransport $transport): ReferenceService
    {
        $api   = new ApiClient($transport);
        $sites = new SiteService(new SiteRepository($this->db), $api, null);

        return new ReferenceService($this->repository, $sites, $api);
    }

    public function testConnectingAsAnAuthorFindsTheApiBase(): void
    {
        $sites = new SiteService(new SiteRepository($this->db), new ApiClient(new JoomlaSiteTransport()), null);

        $site = $sites->create('Site', 'https://example.com', 'tok', allowInsecure: true);

        self::assertSame('https://example.com/index.php/api', $site->apiBase);
    }

    public function testATokenTheSiteRejectsIsReportedAsAnAuthenticationFailure(): void
    {
        $transport = (new JoomlaSiteTransport())->deny('GET', 'users/levels');
        $sites     = new SiteService(new SiteRepository($this->db), new ApiClient($transport), null);

        try {
            $sites->create('Site', 'https://example.com', 'tok', allowInsecure: true);
            self::fail('Expected an ApiException.');
        } catch (ApiException $e) {
            self::assertStringContainsString('core.login.api', $e->getMessage());
            self::assertSame(403, $e->status);
        }
    }

    /** Connecting a site must never fail because the token cannot read one of its lists. */
    public function testSyncCachesWhatAnAuthorCanReadWithoutThrowing(): void
    {
        $site    = $this->seedSite();
        $service = $this->references(new JoomlaSiteTransport());

        $service->sync($site);

        foreach ([
            ReferenceService::KIND_CATEGORIES,
            ReferenceService::KIND_TAGS,
            ReferenceService::KIND_LEVELS,
            ReferenceService::KIND_FIELDS,
            ReferenceService::KIND_LANGUAGES,
        ] as $kind) {
            self::assertNotNull($this->repository->get((int) $site->id, $kind), $kind . ' was not cached');
        }
    }

    /**
     * The config route 403s for an author, so its kind is never cached. The
     * staleness check must not wait for it, or this site would be "never
     * fetched" forever and refresh on every visit.
     */
    public function testAnAuthorSiteStillCountsAsFetchedWithoutTheConfigKind(): void
    {
        $site    = $this->seedSite();
        $service = $this->references(new JoomlaSiteTransport());

        $service->sync($site);

        self::assertNull($this->repository->get((int) $site->id, ReferenceService::KIND_CONFIG));
        self::assertNotNull($service->fetchedAt($site));
    }

    public function testUnicodeSlugsDefaultsToFalseWhenTheConfigRouteIsForbidden(): void
    {
        $site      = $this->seedSite();
        $transport = new JoomlaSiteTransport();

        self::assertFalse($this->references($transport)->unicodeSlugs($site, true));
        self::assertSame(1, $transport->requestsTo('GET', 'config/application'));
        self::assertNull($this->repository->get((int) $site->id, ReferenceService::KIND_CONFIG));
    }

    /**
     * A site read as a Super User and later refreshed with a weaker token must
     * keep the answer it has, not fall back to false.
     */
    public function testAForbiddenRefreshKeepsTheCachedUnicodeSlugsAnswer(): void
    {
        $site = $this->seedSite();
        $this->repository->put((int) $site->id, ReferenceService::KIND_CONFIG, ['unicodeslugs' => true]);

        self::assertTrue($this->references(new JoomlaSiteTransport())->unicodeSlugs($site, true));
    }

    public function testASuperUserReadIsCachedAsTheControl(): void
    {
        $site      = $this->seedSite();
        $transport = new JoomlaSiteTransport(JoomlaSiteTransport::SUPER_USER);

        self::assertTrue($this->references($transport)->unicodeSlugs($site, true));
        self::assertSame(true, $this->repository->get((int) $site->id, ReferenceService::KIND_CONFIG)['payload']['unicodeslugs']);
    }

    /** One field's item request being refused must not hide the field. */
    public function testAFieldWhoseItemRequestIsForbiddenIsInEveryCategory(): void
    {
        $site      = $this->seedSite();
        $transport = (new JoomlaSiteTransport())->deny('GET', 'fields/content/articles/7');

        $fields = $this->references($transport)->fields($site, true);

        self::assertSame([0], $fields[0]['assigned_cat_ids']);
    }

    public function testAFieldItemTheAuthorCanReadKeepsItsCategoryAssignment(): void
    {
        $site = $this->seedSite();

        $fields = $this->references(new JoomlaSiteTransport())->fields($site, true);

        self::assertSame([2], $fields[0]['assigned_cat_ids']);
    }

    /** The manual Refresh button is the one caller that must be told. */
    public function testAStrictRefreshOfAForbiddenListThrows(): void
    {
        $site      = $this->seedSite();
        $transport = (new JoomlaSiteTransport())->deny('GET', 'tags');

        $this->expectException(ApiException::class);
        $this->references($transport)->tags($site, true);
    }

    public function testABestEffortRefreshOfAForbiddenListFallsBackToTheCache(): void
    {
        $site = $this->seedSite();
        $this->repository->put((int) $site->id, ReferenceService::KIND_TAGS, [['id' => 5, 'title' => 'Cached']]);
        $transport = (new JoomlaSiteTransport())->deny('GET', 'tags');

        $tags = $this->references($transport)->tags($site, true, true);

        self::assertSame('Cached', $tags[0]['title']);
    }
}
