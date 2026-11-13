<?php

/**
 * Grafida
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Tests\Unit;

use Grafida\Html\InlineMedia;
use Grafida\Storage\DatabaseFactory;
use Grafida\Storage\Migrator;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * The page origin moved from `boson://app` to `shell://app`. A draft written by Grafida 0.3 references its local
 * pictures by the old URL, which no webview can load any more: migration 11 rewrites them, once.
 */
final class LocalMediaSchemeMigrationTest extends TestCase
{
    public function testMigrationRewritesStoredLocalMediaUrlsOnce(): void
    {
        $real = __DIR__ . '/../../storage/migrations';
        $dir  = sys_get_temp_dir() . '/grafida-mig-' . bin2hex(random_bytes(4));
        mkdir($dir);

        foreach (glob($real . '/*.sql') as $file) {
            if (!str_starts_with(basename($file), '11_')) {
                copy($file, $dir . '/' . basename($file));
            }
        }

        try {
            $db = (new DatabaseFactory())->create(':memory:');
            (new Migrator($db, $dir))->migrate();

            $pdo = $db->getConnection();
            \assert($pdo instanceof PDO);
            $pdo->exec("INSERT INTO sites (title, base_url, created_at, updated_at) VALUES ('s', 'https://example.com', 'x', 'x')");
            $old = '<p><img src="boson://app/api/media/7/raw?rev=abc"> <a href="https://example.com/boson://app/">x</a></p>';
            $stmt = $pdo->prepare(
                "INSERT INTO drafts (site_id, html, images_json, created_at, updated_at) VALUES (1, ?, ?, 'x', 'x'), (1, '<p>plain</p>', '{}', 'x', 'x')"
            );
            $stmt->execute([$old, '{"image_intro":"boson://app/api/media/8/raw?rev=z"}']);

            copy($real . '/11_local_media_scheme_shell.sql', $dir . '/11_local_media_scheme_shell.sql');
            (new Migrator($db, $dir))->migrate();

            $rows = $pdo->query('SELECT html, images_json FROM drafts ORDER BY id')->fetchAll(PDO::FETCH_NUM);

            self::assertSame(
                '<p><img src="shell://app/api/media/7/raw?rev=abc"> <a href="https://example.com/boson://app/">x</a></p>',
                $rows[0][0],
                'Only the local-media prefix is rewritten'
            );
            self::assertSame('{"image_intro":"shell://app/api/media/8/raw?rev=z"}', $rows[0][1]);
            self::assertSame(['<p>plain</p>', '{}'], $rows[1]);
        } finally {
            array_map('unlink', glob($dir . '/*'));
            rmdir($dir);
        }
    }

    public function testLegacyBosonUrlIsStillRecognisedAndParsed(): void
    {
        $media = new InlineMedia();

        self::assertTrue(InlineMedia::isLocalUrl('boson://app/api/media/3/raw?rev=a'));
        self::assertTrue(InlineMedia::isLocalUrl('shell://app/api/media/3/raw?rev=a'));
        self::assertFalse(InlineMedia::isLocalUrl('https://example.com/api/media/3/raw'));
        self::assertSame(3, $media->idFromLocalUrl('boson://app/api/media/3/raw?rev=a'));
        self::assertSame(3, $media->idFromLocalUrl('shell://app/api/media/3/raw'));
        self::assertNull($media->idFromLocalUrl('boson://app/api/media/3/other'));
    }
}
