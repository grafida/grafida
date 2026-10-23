<?php

/**
 * Grafida — Joomla content editing, untethered.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Tests\Unit\Support;

use Grafida\Article\Draft;
use Grafida\Article\DraftRepository;
use Grafida\Html\ContentSplitter;
use Grafida\Http\HttpResponse;
use Grafida\I18n\LanguageService;
use Grafida\Joomla\ApiClient;
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
use Grafida\Text\ContentNormalisationService;
use Grafida\Text\ContentNormaliser;
use Grafida\Tests\Support\TestDatabase;

/**
 * Runs one `tests/corpus/` case through the two pieces of Grafida a second
 * implementation has to reproduce byte-for-byte: {@see ContentSplitter} and the
 * body-building half of {@see PublishService}.
 *
 * ⚠️ **The corpus is a cross-implementation contract, not a PHP fixture.** Its
 * format is documented in `.claude/rules/media-and-publish.md`; keep the two in
 * step, and never regenerate an expectation in bulk to make this suite green —
 * a changed expectation is a changed contract, and has to be argued for one
 * case at a time.
 *
 * The publish runs against a {@see FakeTransport} that answers exactly one URL,
 * so no case may need the network: media uploads, tag creation and category
 * lookups are all avoided by construction (again, see the format doc).
 */
final class CorpusRunner
{
    public const SITE_ID = 1;

    public const BASE_URL = 'https://example.com';

    public const API_BASE = self::BASE_URL . '/api/index.php';

    /**
     * The draft attributes every case starts from, before its own optional
     * `draft.json` overrides any of them. Deliberately boring: a case is about
     * the article *body*, and anything it does not mention must not vary.
     *
     * @var array<string, mixed>
     */
    private const DRAFT_DEFAULTS = [
        'remoteId'       => null,
        'title'          => 'Corpus article',
        'alias'          => 'corpus-article',
        'catid'          => 2,
        'access'         => 1,
        'language'       => 'en-GB',
        'state'          => 1,
        'fields'         => [],
        'tags'           => [],
        'images'         => [],
        'metadesc'       => '',
        'metakey'        => '',
        'createdByAlias' => '',
    ];

    /** Absolute path of the corpus directory. */
    public static function corpusDir(): string
    {
        return \dirname(__DIR__, 2) . '/corpus';
    }

    /**
     * Every case directory name, sorted, so the suite runs in a stable order.
     *
     * @return list<string>
     */
    public static function caseNames(): array
    {
        $entries = scandir(self::corpusDir());
        $names   = [];

        foreach ($entries === false ? [] : $entries as $entry) {
            if (str_starts_with($entry, '.')) {
                continue;
            }

            if (is_dir(self::corpusDir() . '/' . $entry)) {
                $names[] = $entry;
            }
        }

        sort($names);

        return $names;
    }

    /**
     * The whole of a case's expected output, as this implementation produces it.
     *
     * @return array{introtext: string, fulltext: string, body: array<string, mixed>,
     *               request: array{method: string, path: string}}
     */
    public static function run(string $case): array
    {
        $dir   = self::corpusDir() . '/' . $case;
        $input = self::read($dir . '/input.html');
        $split = (new ContentSplitter())->split($input);
        $write = self::publish($dir, $input);

        return [
            'introtext' => $split['introtext'],
            'fulltext'  => $split['fulltext'],
            'body'      => $write['body'],
            'request'   => $write['request'],
        ];
    }

    /**
     * A corpus file, with the single trailing newline git wants stripped again.
     * `input.html` and the two `expected-*.html` files are all read this way, so
     * the round trip through the filesystem adds nothing to either side.
     */
    public static function read(string $path): string
    {
        $raw = file_get_contents($path);

        if ($raw === false) {
            throw new \RuntimeException('Corpus file could not be read: ' . $path);
        }

        return preg_replace('/\n\z/', '', $raw) ?? $raw;
    }

    /**
     * @return array<string, mixed>|null null when the (optional) file is absent.
     */
    public static function readJson(string $path): ?array
    {
        if (!is_file($path)) {
            return null;
        }

        $decoded = json_decode(self::read($path), true);

        if (!is_array($decoded)) {
            throw new \RuntimeException('Corpus file is not a JSON object: ' . $path);
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * Publishes the case's draft against a fake transport and returns the write
     * that reached the wire: its method and route, and the flat top-level JSON
     * object that was its body.
     *
     * `version_note` is stripped from the body: it embeds `App::VERSION`, so
     * keeping it would make every corpus expectation stale on the next release
     * while saying nothing about how the *content* is built. That it is sent at
     * all is pinned elsewhere (gh-17).
     *
     * @return array{body: array<string, mixed>, request: array{method: string, path: string}}
     */
    private static function publish(string $dir, string $input): array
    {
        $spec = array_merge(self::DRAFT_DEFAULTS, self::readJson($dir . '/draft.json') ?? []);

        $db         = TestDatabase::memory();
        $connection = TestDatabase::connection($db);
        $connection->exec(
            'INSERT INTO sites (id, title, base_url, api_base, insecure_token, created_at, updated_at) '
            . 'VALUES (' . self::SITE_ID . ", 'Corpus', '" . self::BASE_URL . "', '" . self::API_BASE . "', 'tok', "
            . "'2026-01-01 00:00:00', '2026-01-01 00:00:00')"
        );

        // Seed every reference cache the publish reads, so it never reaches for
        // the network. An absent site-fields.json / site-tags.json means "the
        // site has none", which is the common case.
        $references = new ReferenceRepository($db);
        $references->put(self::SITE_ID, ReferenceService::KIND_FIELDS, self::readList($dir . '/site-fields.json'));
        $references->put(self::SITE_ID, ReferenceService::KIND_TAGS, self::readList($dir . '/site-tags.json'));
        $references->put(self::SITE_ID, ReferenceService::KIND_CATEGORIES, []);

        $transport = new FakeTransport();
        $apiClient = new ApiClient($transport);
        $sites     = new SiteService(new SiteRepository($db), $apiClient, null);
        $drafts    = new DraftRepository($db);
        $media     = new MediaRepository($db);

        $remoteId = is_int($spec['remoteId'] ?? null) ? $spec['remoteId'] : null;
        $url      = self::API_BASE . '/v1/content/articles' . ($remoteId === null ? '' : '/' . $remoteId);

        $transport->on($url, new HttpResponse(
            $remoteId === null ? 201 : 200,
            (string) json_encode(['data' => [
                'type'       => 'articles',
                'id'         => (string) ($remoteId ?? 42),
                'attributes' => ['title' => $spec['title']],
            ]]),
            ['Content-Type' => 'application/vnd.api+json'],
        ));

        $publish = new PublishService(
            $sites,
            $apiClient,
            new ReferenceService($references, $sites, $apiClient),
            $drafts,
            $media,
            new LanguageService(new SettingsRepository($db), \dirname(__DIR__, 3)),
            new InlineImageExtractor($media),
            new MediaUploadTarget($apiClient),
            new ContentNormaliser(new ContentNormalisationService(new SettingsRepository($db))),
        );

        $publish->publish(self::draft($spec, $input), self::site());

        foreach ($transport->requests as $request) {
            if ($request['url'] !== $url || $request['body'] === null) {
                continue;
            }

            $body = json_decode($request['body'], true);

            if (!is_array($body)) {
                throw new \RuntimeException('The publish body was not a JSON object.');
            }

            unset($body['version_note']);

            /** @var array<string, mixed> $body */
            return [
                'body'    => $body,
                'request' => [
                    'method' => $request['method'],
                    'path'   => substr($url, \strlen(self::API_BASE)),
                ],
            ];
        }

        throw new \RuntimeException('The publish sent no article write.');
    }

    /**
     * @param array<string, mixed> $spec
     */
    private static function draft(array $spec, string $html): Draft
    {
        /** @var array<string, mixed> $fields */
        $fields = is_array($spec['fields']) ? $spec['fields'] : [];
        /** @var list<string> $tags */
        $tags = is_array($spec['tags']) ? array_values(array_filter($spec['tags'], 'is_string')) : [];
        /** @var array<string, mixed> $images */
        $images = is_array($spec['images']) ? $spec['images'] : [];

        return new Draft(
            id: null,
            siteId: self::SITE_ID,
            remoteId: is_int($spec['remoteId']) ? $spec['remoteId'] : null,
            title: (string) $spec['title'],
            alias: (string) $spec['alias'],
            catid: is_int($spec['catid']) ? $spec['catid'] : null,
            access: (int) $spec['access'],
            language: (string) $spec['language'],
            state: (int) $spec['state'],
            html: $html,
            fields: $fields,
            tags: $tags,
            images: $images,
            metadesc: (string) $spec['metadesc'],
            metakey: (string) $spec['metakey'],
            createdByAlias: (string) $spec['createdByAlias'],
        );
    }

    private static function site(): Site
    {
        return new Site(
            id: self::SITE_ID,
            title: 'Corpus',
            baseUrl: self::BASE_URL,
            apiBase: self::API_BASE,
            secretRef: null,
            hasInsecureToken: true,
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function readList(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }

        $decoded = json_decode(self::read($path), true);

        if (!is_array($decoded)) {
            throw new \RuntimeException('Corpus file is not a JSON array: ' . $path);
        }

        $out = [];

        foreach ($decoded as $entry) {
            if (is_array($entry)) {
                /** @var array<string, mixed> $entry */
                $out[] = $entry;
            }
        }

        return $out;
    }
}
