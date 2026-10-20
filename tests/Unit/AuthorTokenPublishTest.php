<?php

/**
 * Grafida — Joomla content editing, untethered.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Tests\Unit;

use Grafida\Article\Draft;
use Grafida\Article\DraftRepository;
use Grafida\I18n\LanguageService;
use Grafida\Joomla\ApiClient;
use Grafida\Joomla\ApiException;
use Grafida\Media\InlineImageExtractor;
use Grafida\Media\MediaRepository;
use Grafida\Media\MediaUploadTarget;
use Grafida\Publish\PublishService;
use Grafida\Reference\ReferenceRepository;
use Grafida\Reference\ReferenceService;
use Grafida\Site\Site;
use Grafida\Site\SiteRepository;
use Grafida\Site\SiteService;
use Grafida\Storage\SettingsRepository;
use Grafida\Tests\Support\TestDatabase;
use Grafida\Tests\Unit\Support\JoomlaSiteTransport;
use Grafida\Text\ContentNormalisationService;
use Grafida\Text\ContentNormaliser;
use Joomla\Database\DatabaseInterface;

/**
 * Publishing with a token whose user is not a Super User.
 *
 * The Author profile itself withholds only the admin-only routes, which a
 * publish never calls; what an author can do beyond that depends on the site's
 * permission set. So the cases that matter here deny the *specific* write that
 * would be refused (a new tag, a media upload, the article itself) and pin what
 * a publish does about it: it fails with the site's 403 and, crucially, leaves
 * the article unwritten rather than publishing a half-built one.
 */
final class AuthorTokenPublishTest extends TestCase
{
    private const API = 'https://example.com/index.php/api';

    private DatabaseInterface $db;
    private DraftRepository $drafts;
    private MediaRepository $media;
    private Site $site;

    protected function setUp(): void
    {
        $this->db = TestDatabase::memory();
        TestDatabase::connection($this->db)->exec(
            'INSERT INTO sites (id, title, base_url, api_base, insecure_token, created_at, updated_at) '
            . "VALUES (1, 'Site', 'https://example.com', '" . self::API . "', 'tok', "
            . "'2026-01-01 00:00:00', '2026-01-01 00:00:00')"
        );

        $this->drafts = new DraftRepository($this->db);
        $this->media  = new MediaRepository($this->db);
        $this->site   = new Site(
            id: 1,
            title: 'Site',
            baseUrl: 'https://example.com',
            apiBase: self::API,
            secretRef: null,
            hasInsecureToken: true,
        );
    }

    private function publishService(JoomlaSiteTransport $transport): PublishService
    {
        $api      = new ApiClient($transport);
        $sites    = new SiteService(new SiteRepository($this->db), $api, null);
        $refs     = new ReferenceService(new ReferenceRepository($this->db), $sites, $api);
        $language = new LanguageService(new SettingsRepository($this->db), \dirname(__DIR__, 2));

        return new PublishService(
            $sites,
            $api,
            $refs,
            $this->drafts,
            $this->media,
            $language,
            new InlineImageExtractor($this->media),
            new MediaUploadTarget($api),
            new ContentNormaliser(new ContentNormalisationService(new SettingsRepository($this->db))),
        );
    }

    /**
     * @param list<string>         $tags
     * @param array<string, mixed> $images
     */
    private function draft(array $tags = [], array $images = [], ?int $remoteId = null): Draft
    {
        $draft = new Draft(
            id: null,
            siteId: 1,
            remoteId: $remoteId,
            title: 'Author article',
            alias: 'author-article',
            catid: null,
            access: 1,
            language: '*',
            state: 1,
            html: '<p>Body</p>',
            tags: $tags,
            images: $images,
        );
        $draft->id = $this->drafts->insert($draft);

        return $draft;
    }

    /** @return array<string, mixed> */
    private function articleBody(JoomlaSiteTransport $transport): array
    {
        foreach ($transport->requests as $request) {
            if ($request['method'] === 'POST' && $request['url'] === self::API . '/v1/content/articles') {
                $body = json_decode((string) $request['body'], true);

                return is_array($body) ? $body : [];
            }
        }

        return [];
    }

    public function testAnAuthorPublishesAnArticleUsingAnExistingTag(): void
    {
        $transport = new JoomlaSiteTransport();

        $result = $this->publishService($transport)->publish($this->draft(['Existing']), $this->site);

        self::assertSame(42, $result['remoteId']);
        self::assertSame([5], $this->articleBody($transport)['tags']);
        self::assertSame(0, $transport->requestsTo('POST', 'tags'));
    }

    /** Control: the same draft, with a token allowed to create the tag. */
    public function testATokenAllowedToCreateTagsCreatesTheMissingOne(): void
    {
        $transport = new JoomlaSiteTransport(JoomlaSiteTransport::SUPER_USER);

        $this->publishService($transport)->publish($this->draft(['Brand new']), $this->site);

        self::assertSame([99], $this->articleBody($transport)['tags']);
    }

    public function testAForbiddenNewTagFailsThePublishBeforeTheArticleIsWritten(): void
    {
        $transport = (new JoomlaSiteTransport())->deny('POST', 'tags');

        try {
            $this->publishService($transport)->publish($this->draft(['Brand new']), $this->site);
            self::fail('Expected an ApiException.');
        } catch (ApiException $e) {
            self::assertSame(403, $e->status);
        }

        self::assertSame(0, $transport->requestsTo('POST', 'content/articles'));
    }

    public function testAForbiddenMediaUploadFailsThePublishBeforeTheArticleIsWritten(): void
    {
        $mediaId   = $this->media->store(1, null, 'cat.png', 'image/png', 'raw-bytes', 640, 480);
        $transport = (new JoomlaSiteTransport())->deny('POST', 'media/files');

        try {
            $this->publishService($transport)->publish(
                $this->draft(images: ['image_intro' => 'grafida-media://' . $mediaId]),
                $this->site
            );
            self::fail('Expected an ApiException.');
        } catch (ApiException $e) {
            self::assertSame(403, $e->status);
        }

        self::assertSame(1, $transport->requestsTo('POST', 'media/files'));
        self::assertSame(0, $transport->requestsTo('POST', 'content/articles'));
    }

    /**
     * A token that may upload but not list the Media Manager's filesystems
     * still publishes; the upload simply names no adapter, which is what
     * Grafida sent before it learned to ask (see MediaUploadTarget).
     */
    public function testAnUnlistableMediaManagerStillUploadsWithoutAnAdapter(): void
    {
        $mediaId   = $this->media->store(1, null, 'cat.png', 'image/png', 'raw-bytes', 640, 480);
        $transport = (new JoomlaSiteTransport())->deny('GET', 'media/adapters');

        $this->publishService($transport)->publish(
            $this->draft(images: ['image_intro' => 'grafida-media://' . $mediaId]),
            $this->site
        );

        $upload = null;

        foreach ($transport->requests as $request) {
            if ($request['method'] === 'POST' && str_ends_with($request['url'], '/v1/media/files')) {
                $upload = json_decode((string) $request['body'], true);
            }
        }

        self::assertIsArray($upload);
        self::assertSame('grafida/' . $mediaId . '-cat.png', $upload['path']);
        self::assertSame(1, $transport->requestsTo('POST', 'content/articles'));
    }

    /** An author editing an article that is not theirs: the site's refusal reaches the caller intact. */
    public function testAForbiddenArticleWriteSurfacesTheSitesStatus(): void
    {
        $transport = (new JoomlaSiteTransport())->deny('PATCH', 'content/articles/9');

        try {
            $this->publishService($transport)->publish($this->draft(remoteId: 9), $this->site);
            self::fail('Expected an ApiException.');
        } catch (ApiException $e) {
            self::assertSame(403, $e->status);
            self::assertSame(['Forbidden'], $e->apiErrors);
        }
    }
}
