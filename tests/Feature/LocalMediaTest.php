<?php

/**
 * Grafida — Joomla content editing, untethered.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Tests\Feature;

use Grafida\Shell\Request;
use Grafida\Shell\Response;
use Grafida\Application\Container;
use Grafida\Application\Kernel;
use Grafida\Article\Draft;
use Grafida\Article\DraftRepository;
use Grafida\Media\LocalMediaUrl;
use Grafida\Media\MediaRepository;
use Grafida\Tests\Support\TestContainer;
use Grafida\Tests\Support\TestDatabase;
use Joomla\Database\DatabaseInterface;
use PHPUnit\Framework\TestCase;

/**
 * Exercises the gh-36 local-media surface end to end through the kernel:
 * `GET /api/media/{id}/raw` (the endpoint an article `<img src>` actually
 * points at), the Local Media tab's CRUD (`/api/sites/{id}/local-media`,
 * `/api/media/{id}/rename`, `/api/media/{id}/content`, `DELETE
 * /api/media/{id}`), and the changed `POST /api/sites/{id}/media` response
 * shape (step 4's contract change: `{id, url, width, height}`, no more
 * `dataUri`) — plus, since gh-43, the server-side resync of any **closed**
 * draft's stored HTML when a referenced blob's bytes (and therefore
 * dimensions) change.
 */
final class LocalMediaTest extends TestCase
{
    private Container $container;

    private Kernel $kernel;

    private DatabaseInterface $db;

    protected function setUp(): void
    {
        $this->container = TestContainer::create();
        $this->kernel     = $this->container->get(Kernel::class);
        $this->db         = $this->container->get(DatabaseInterface::class);
    }

    /** Inserts a bare site row (media_blobs reference sites via a foreign key). */
    private function seedSite(string $title = 'Site'): int
    {
        $now = gmdate('Y-m-d H:i:s');
        $pdo = TestDatabase::connection($this->db);
        $pdo->prepare(
            'INSERT INTO sites (title, base_url, created_at, updated_at) VALUES (?, ?, ?, ?)'
        )->execute([$title, 'https://example.test', $now, $now]);

        return (int) $pdo->lastInsertId();
    }

    private function raw(string $method, string $path, ?string $body = null): Response
    {
        return $this->kernel->handle(new Request($method, 'shell://app' . $path, [], $body ?? ''));
    }

    /** @return array{0: int, 1: mixed} */
    private function call(string $method, string $path, ?string $body = null): array
    {
        $response = $this->raw($method, $path, $body);

        return [(int) (string) $response->status, json_decode((string) $response->body, true)];
    }

    private function header(Response $response, string $name): ?string
    {
        foreach ($response->headers as $key => $value) {
            if (strtolower((string) $key) === strtolower($name)) {
                return (string) $value;
            }
        }

        return null;
    }

    public function testRawEndpointServesStoredBytesWithMimeAndNoStore(): void
    {
        $siteId = $this->seedSite();
        $media  = $this->container->get(MediaRepository::class);
        $id     = $media->store($siteId, null, 'photo.png', 'image/png', 'raw-image-bytes');

        $response = $this->raw('GET', '/api/media/' . $id . '/raw');

        self::assertSame(200, (int) (string) $response->status);
        self::assertSame('image/png', $this->header($response, 'Content-Type'));
        self::assertSame('raw-image-bytes', (string) $response->body);
        self::assertStringContainsString('no-store', (string) $this->header($response, 'Cache-Control'));
    }

    /**
     * gh-72: the image URL field is filled from this before the picture has ever
     * been uploaded, so the answer is a prediction and says so.
     */
    public function testTargetEndpointPredictsThePublishPathOfAnUnpublishedBlob(): void
    {
        $siteId = $this->seedSite();
        $media  = $this->container->get(MediaRepository::class);
        $id     = $media->store($siteId, null, 'my photo.png', 'image/png', 'bytes');

        [$status, $json] = $this->call('GET', '/api/media/' . $id . '/target');

        self::assertSame(200, $status);
        self::assertTrue($json['data']['predicted']);
        self::assertSame('images/grafida/' . $id . '-my-photo.png', $json['data']['path']);
    }

    /** An already-uploaded blob has a real path, and must not be reported as a guess. */
    public function testTargetEndpointReportsTheRecordedPathOfAPublishedBlob(): void
    {
        $siteId = $this->seedSite();
        $media  = $this->container->get(MediaRepository::class);
        $id     = $media->store($siteId, null, 'photo.png', 'image/png', 'bytes');
        $media->markUploaded($id, 'local-images:/grafida/photo.png', 'images/grafida/photo.png');

        [$status, $json] = $this->call('GET', '/api/media/' . $id . '/target');

        self::assertSame(200, $status);
        self::assertFalse($json['data']['predicted']);
        self::assertSame('images/grafida/photo.png', $json['data']['path']);
    }

    public function testTargetEndpointForMissingIdIs404(): void
    {
        [$status, $json] = $this->call('GET', '/api/media/999999/target');

        self::assertSame(404, $status);
        self::assertFalse($json['ok']);
    }

    public function testRawEndpointForMissingIdIs404WithJsonBody(): void
    {
        [$status, $json] = $this->call('GET', '/api/media/999999/raw');

        self::assertSame(404, $status);
        self::assertFalse($json['ok']);
    }

    public function testLocalMediaListingCarriesUrlAndNeverBlobData(): void
    {
        $siteId = $this->seedSite();
        $media  = $this->container->get(MediaRepository::class);
        $media->store($siteId, null, 'a.png', 'image/png', str_repeat('x', 32));

        [$status, $json] = $this->call('GET', '/api/sites/' . $siteId . '/local-media');

        self::assertSame(200, $status);
        self::assertCount(1, $json['data']['entries']);
        $entry = $json['data']['entries'][0];
        self::assertArrayHasKey('url', $entry);
        self::assertArrayNotHasKey('data', $entry);
        self::assertStringContainsString('shell://app/api/media/', $entry['url']);
    }

    public function testLocalMediaListingWorksWithoutAConnectedSite(): void
    {
        // A site with no stored token is, by definition, "not connected" —
        // the Local Media tab must still work, since media_blobs is entirely
        // local storage and never talks to the site's REST API.
        $siteId = $this->seedSite();

        [$status, $json] = $this->call('GET', '/api/sites/' . $siteId . '/local-media');

        self::assertSame(200, $status);
        self::assertSame([], $json['data']['entries']);
    }

    public function testRenameRejectsSlashColonAndEmpty(): void
    {
        $siteId = $this->seedSite();
        $media  = $this->container->get(MediaRepository::class);
        $id     = $media->store($siteId, null, 'a.png', 'image/png', 'bytes');

        foreach (['bad/name', 'bad:name', ''] as $badName) {
            [$status, $json] = $this->call('POST', '/api/media/' . $id . '/rename', json_encode(['filename' => $badName]));
            self::assertSame(400, $status, $badName);
            self::assertFalse($json['ok'], $badName);
        }
    }

    public function testRenameSucceedsAndReflectsInUrl(): void
    {
        $siteId = $this->seedSite();
        $media  = $this->container->get(MediaRepository::class);
        $id     = $media->store($siteId, null, 'a.png', 'image/png', 'bytes');

        [$status, $json] = $this->call('POST', '/api/media/' . $id . '/rename', json_encode(['filename' => 'renamed']));

        self::assertSame(200, $status);
        self::assertSame('renamed.png', $json['data']['filename']);
        self::assertStringContainsString((string) $id . '/raw', $json['data']['url']);
    }

    public function testContentUpdateIsReadableBackFromRawAndChangesUrl(): void
    {
        $siteId = $this->seedSite();
        $media  = $this->container->get(MediaRepository::class);
        $id     = $media->store($siteId, null, 'a.png', 'image/png', 'original-bytes');

        [, $before] = $this->call('GET', '/api/sites/' . $siteId . '/local-media');
        $originalUrl = $before['data']['entries'][0]['url'];

        // The revision token is derived from updated_at at second resolution
        // (LocalMediaUrl::token()); force a distinct second so the assertion
        // that the URL changes is not a coin flip on how fast the test runs.
        sleep(1);

        $newBytes = base64_encode('new-bytes-after-edit');
        [$status, $json] = $this->call('POST', '/api/media/' . $id . '/content', json_encode(['dataBase64' => $newBytes]));

        self::assertSame(200, $status);
        self::assertNotSame($originalUrl, $json['data']['url']);

        $response = $this->raw('GET', '/api/media/' . $id . '/raw');
        self::assertSame('new-bytes-after-edit', (string) $response->body);
    }

    public function testDeleteThenRawIs404(): void
    {
        $siteId = $this->seedSite();
        $media  = $this->container->get(MediaRepository::class);
        $id     = $media->store($siteId, null, 'a.png', 'image/png', 'bytes');

        [$status] = $this->call('DELETE', '/api/media/' . $id);
        self::assertSame(200, $status);

        $response = $this->raw('GET', '/api/media/' . $id . '/raw');
        self::assertSame(404, (int) (string) $response->status);
    }

    /** Real PNG bytes of the given size, via GD — replaceData() re-derives dimensions from the actual bytes. */
    private function png(int $width, int $height): string
    {
        $img = imagecreatetruecolor($width, $height);
        self::assertNotFalse($img);

        ob_start();
        imagepng($img);
        $data = ob_get_clean();
        imagedestroy($img);

        self::assertIsString($data);

        return $data;
    }

    /** Inserts a bare draft carrying the given HTML and returns its id. */
    private function seedDraft(int $siteId, string $html): int
    {
        $drafts = $this->container->get(DraftRepository::class);

        return $drafts->insert(new Draft(
            id: null,
            siteId: $siteId,
            remoteId: null,
            title: 'Draft',
            alias: 'draft',
            catid: null,
            access: 1,
            language: '*',
            state: 1,
            html: $html,
        ));
    }

    public function testUpdatingContentResyncsAClosedDraftAtIntrinsicSizeToTheNewDimensions(): void
    {
        $siteId = $this->seedSite();
        $media  = $this->container->get(MediaRepository::class);
        $drafts = $this->container->get(DraftRepository::class);

        $id  = $media->store($siteId, null, 'a.png', 'image/png', 'orig-bytes', 640, 480);
        $rev = $media->findMeta($id);
        self::assertNotNull($rev);

        // The article never hand-resized this image: its width/height still
        // match the blob's OLD intrinsic size exactly.
        $oldSrc  = LocalMediaUrl::build($id, $rev['updated_at'] ?? $rev['created_at']);
        $html    = '<p><img src="' . $oldSrc . '" width="640" height="480"></p>';
        $draftId = $this->seedDraft($siteId, $html);

        // Force a distinct second so the new rev token cannot coincide with
        // the old one (same rationale as testContentUpdateIsReadableBack…).
        sleep(1);

        $newBytes = $this->png(1280, 960);
        [$status, $json] = $this->call(
            'POST',
            '/api/media/' . $id . '/content',
            json_encode(['dataBase64' => base64_encode($newBytes)]),
        );

        self::assertSame(200, $status);
        self::assertSame(1280, $json['data']['width']);
        self::assertSame(960, $json['data']['height']);
        self::assertSame(640, $json['data']['oldWidth']);
        self::assertSame(480, $json['data']['oldHeight']);

        $resynced = $drafts->find($draftId);
        self::assertNotNull($resynced);
        self::assertStringContainsString('width="1280"', $resynced->html);
        self::assertStringContainsString('height="960"', $resynced->html);
        self::assertStringContainsString($json['data']['url'], $resynced->html);
        self::assertStringNotContainsString($oldSrc, $resynced->html);
    }

    public function testUpdatingContentPreservesAHandSetWidthAndReRatiosTheHeight(): void
    {
        $siteId = $this->seedSite();
        $media  = $this->container->get(MediaRepository::class);
        $drafts = $this->container->get(DraftRepository::class);

        $id = $media->store($siteId, null, 'a.png', 'image/png', 'orig-bytes', 640, 480);

        // A deliberate in-article size: 300 does not match the blob's OLD
        // intrinsic width (640), so it must be kept — never adopted wholesale.
        $draftId = $this->seedDraft(
            $siteId,
            '<p><img src="shell://app/api/media/' . $id . '/raw?rev=stale" width="300" height="225"></p>',
        );

        $newBytes = $this->png(1280, 640);
        [$status] = $this->call(
            'POST',
            '/api/media/' . $id . '/content',
            json_encode(['dataBase64' => base64_encode($newBytes)]),
        );

        self::assertSame(200, $status);

        $resynced = $drafts->find($draftId);
        self::assertNotNull($resynced);
        // width kept, height re-ratioed: round(300 * 640 / 1280) = 150.
        self::assertStringContainsString('width="300"', $resynced->html);
        self::assertStringContainsString('height="150"', $resynced->html);
    }

    public function testUpdatingContentLeavesADraftReferencingADifferentBlobByteIdentical(): void
    {
        $siteId = $this->seedSite();
        $media  = $this->container->get(MediaRepository::class);
        $drafts = $this->container->get(DraftRepository::class);

        $editedId    = $media->store($siteId, null, 'a.png', 'image/png', 'orig-bytes', 640, 480);
        $untouchedId = $media->store($siteId, null, 'b.png', 'image/png', 'other-bytes', 200, 100);

        $untouchedHtml = '<p><img src="shell://app/api/media/' . $untouchedId . '/raw?rev=xyz" width="200" height="100"></p>';
        $draftId       = $this->seedDraft($siteId, $untouchedHtml);

        [$status] = $this->call(
            'POST',
            '/api/media/' . $editedId . '/content',
            json_encode(['dataBase64' => base64_encode($this->png(1280, 960))]),
        );

        self::assertSame(200, $status);

        $after = $drafts->find($draftId);
        self::assertNotNull($after);
        self::assertSame($untouchedHtml, $after->html, 'a draft referencing a different blob must be byte-identical afterwards');
    }

    public function testMediaBlobEndpointReturnsFilenameMimeAndDimensionsAlongsideDataUri(): void
    {
        $siteId = $this->seedSite();
        $media  = $this->container->get(MediaRepository::class);
        $id     = $media->store($siteId, null, 'photo.png', 'image/png', 'raw-image-bytes', 640, 480);

        [$status, $json] = $this->call('GET', '/api/media/' . $id);

        self::assertSame(200, $status);
        self::assertArrayHasKey('dataUri', $json['data']);
        self::assertSame('photo.png', $json['data']['filename']);
        self::assertSame('image/png', $json['data']['mime']);
        self::assertSame(640, $json['data']['width']);
        self::assertSame(480, $json['data']['height']);
    }

    public function testUploadOfflineMediaReturnsIdUrlWidthHeightNotDataUri(): void
    {
        $siteId = $this->seedSite();

        // A 1x1 transparent PNG, so ImageInfo::dimensions() has real bytes to sniff.
        $png = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
            true,
        );
        self::assertIsString($png);

        [$status, $json] = $this->call(
            'POST',
            '/api/sites/' . $siteId . '/media',
            json_encode(['dataBase64' => base64_encode($png), 'mime' => 'image/png']),
        );

        self::assertSame(201, $status);
        self::assertArrayHasKey('id', $json['data']);
        self::assertArrayHasKey('url', $json['data']);
        self::assertArrayNotHasKey('dataUri', $json['data']);
        self::assertSame(1, $json['data']['width']);
        self::assertSame(1, $json['data']['height']);
        self::assertStringContainsString('shell://app/api/media/', $json['data']['url']);
    }
}
