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
use Grafida\Tests\Support\TestDatabase;
use Joomla\Database\DatabaseInterface;

final class DraftRepositoryTest extends TestCase
{
    private DatabaseInterface $db;

    protected function setUp(): void
    {
        $this->db = TestDatabase::memory();
        $connection = TestDatabase::connection($this->db);
        $connection->exec(
            'INSERT INTO sites (id, title, base_url, created_at, updated_at) '
            . "VALUES (1, 'Site', 'https://example.com', '2026-01-01 00:00:00', '2026-01-01 00:00:00')"
        );
        $connection->exec(
            'INSERT INTO sites (id, title, base_url, created_at, updated_at) '
            . "VALUES (2, 'Other', 'https://other.example', '2026-01-01 00:00:00', '2026-01-01 00:00:00')"
        );
    }

    private function repo(): DraftRepository
    {
        return new DraftRepository($this->db);
    }

    private function sample(): Draft
    {
        return new Draft(
            id: null,
            siteId: 1,
            remoteId: null,
            title: 'Hello',
            alias: 'hello',
            catid: null,
            access: 1,
            language: '*',
            state: 1,
            html: '<p>Body</p>',
            fields: ['colour' => 'blue'],
            tags: ['news'],
            images: ['image_intro' => 'images/a.png'],
            metadesc: 'desc',
            metakey: 'key',
            createdByAlias: 'Guest Author',
        );
    }

    public function testInsertPersistsAndRoundTrips(): void
    {
        $id = $this->repo()->insert($this->sample());

        self::assertGreaterThan(0, $id);

        $back = $this->repo()->find($id);

        self::assertNotNull($back);
        self::assertSame('Hello', $back->title);
        self::assertSame(['colour' => 'blue'], $back->fields);
        self::assertSame(['news'], $back->tags);
        self::assertSame(['image_intro' => 'images/a.png'], $back->images);
        self::assertSame('Guest Author', $back->createdByAlias);
    }

    public function testUpdateChangesStoredValues(): void
    {
        $repo = $this->repo();
        $id   = $repo->insert($this->sample());

        $draft = $repo->find($id);
        self::assertNotNull($draft);

        $draft->title = 'Changed';
        $draft->html  = '<p>New</p>';
        $repo->update($draft);

        $back = $repo->find($id);
        self::assertNotNull($back);
        self::assertSame('Changed', $back->title);
        self::assertSame('<p>New</p>', $back->html);
        self::assertSame(1, $back->siteId);
    }

    public function testFindByRemoteMatchesSiteAndRemoteId(): void
    {
        $repo  = $this->repo();
        $draft = $this->sample();
        $draft->remoteId = 99;
        $id = $repo->insert($draft);

        self::assertSame($id, $repo->findByRemote(1, 99)?->id);
        self::assertNull($repo->findByRemote(2, 99), 'must not match a different site');
        self::assertNull($repo->findByRemote(1, 100), 'must not match a different remote id');
    }

    public function testUpdateCanRepointDraftToAnotherSite(): void
    {
        $repo  = $this->repo();
        $draft = $this->sample();
        $draft->remoteId = 99;
        $id = $repo->insert($draft);

        // Re-point at site 2 and unlink it from its remote article.
        $moved = $repo->find($id);
        self::assertNotNull($moved);
        $moved->siteId   = 2;
        $moved->remoteId = null;
        $repo->update($moved);

        $back = $repo->find($id);
        self::assertNotNull($back);
        self::assertSame(2, $back->siteId);
        self::assertNull($back->remoteId);
        self::assertCount(0, $repo->forSite(1));
        self::assertCount(1, $repo->forSite(2));
    }

    public function testListReferencingMediaMatchesOnRawUrlAndSite(): void
    {
        $repo = $this->repo();

        $draft = $this->sample();
        $draft->html = '<p><img src="shell://app/api/media/1/raw?rev=abc"></p>';
        $id = $repo->insert($draft);

        // A trailing "/raw" on a DIFFERENT id must not be matched by a "%1%"
        // substring search — this is the trap the needle's trailing "/raw"
        // exists to close.
        $other = $this->sample();
        $other->html = '<p><img src="shell://app/api/media/11/raw?rev=xyz"></p>';
        $repo->insert($other);

        $unrelated = $this->sample();
        $unrelated->html = '<p>No images here.</p>';
        $repo->insert($unrelated);

        // Same reference, but on a different site — must not match either.
        $elsewhere = $this->sample();
        $elsewhere->siteId = 2;
        $elsewhere->html   = '<p><img src="shell://app/api/media/1/raw?rev=abc"></p>';
        $repo->insert($elsewhere);

        $found = $repo->listReferencingMedia(1, 1);

        self::assertCount(1, $found);
        self::assertSame($id, $found[0]->id);
    }

    public function testListReferencingMediaReturnsEmptyWhenNothingMatches(): void
    {
        self::assertSame([], $this->repo()->listReferencingMedia(1, 999));
    }

    public function testUpdateHtmlOverwritesHtmlAndBumpsUpdatedAt(): void
    {
        $repo = $this->repo();
        $id   = $repo->insert($this->sample());

        $before = $repo->find($id);
        self::assertNotNull($before);

        $repo->updateHtml($id, '<p>Resynced</p>');

        $after = $repo->find($id);
        self::assertNotNull($after);
        self::assertSame('<p>Resynced</p>', $after->html);
        self::assertNotNull($after->updatedAt);
        // Every other column is left exactly as it was.
        self::assertSame($before->title, $after->title);
        self::assertSame($before->fields, $after->fields);
    }

    public function testSetRemoteIdAndDelete(): void
    {
        $repo = $this->repo();
        $id   = $repo->insert($this->sample());

        $repo->setRemoteId($id, 42);
        self::assertSame(42, $repo->find($id)?->remoteId);

        $repo->delete($id);
        self::assertNull($repo->find($id));
        self::assertCount(0, $repo->forSite(1));
    }
}
