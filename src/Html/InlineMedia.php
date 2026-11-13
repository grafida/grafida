<?php

/**
 * Grafida — Joomla content editing, untethered.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Html;

use Grafida\Media\ImageDimensions;

/**
 * Handles images that were inserted while editing offline.
 *
 * An image picked through Grafida's own media flow is inserted as
 * `<img src="shell://app/api/media/{id}/raw?rev=…">` (gh-36: a local blob
 * served by the worker, so its bytes never sit in the editor DOM or the
 * `drafts.html` column as base64). An image **pasted or dragged straight into
 * the editor** (e.g. dropped from a web page or another app) still lands as a
 * bare `<img src="data:...">` with no tag, because it never went through the
 * in-editor upload handler, and a draft saved before gh-36 may still carry
 * tagged `data:` images too (see the legacy-draft migration). Before
 * publishing, *every* such image — local-URL or data: — must be uploaded and
 * swapped for the real public URL returned by the Media Manager, or a
 * `shell://` / `data:` src would leak into the published article and resolve
 * to nothing on the live site.
 */
final class InlineMedia
{
    public const ATTRIBUTE = 'data-grafida-media-id';

    /** Prefix of the URL the worker serves a not-yet-published image from (gh-36). */
    public const LOCAL_URL_PREFIX = 'shell://app/api/media/';

    /**
     * The prefix Grafida 0.3 and earlier (Boson) wrote. Migration 11 rewrites it in stored drafts; it is still
     * recognised on the way in, e.g. a `.grafida` export whose blob was deleted before it was made.
     */
    public const LEGACY_LOCAL_URL_PREFIX = 'boson://app/api/media/';

    /** True when `$src` is a local-media reference, in the current or the legacy scheme. */
    public static function isLocalUrl(string $src): bool
    {
        return str_starts_with($src, self::LOCAL_URL_PREFIX) || str_starts_with($src, self::LEGACY_LOCAL_URL_PREFIX);
    }

    /**
     * Rewrites every inline offline image (a `shell://` local-media URL or a
     * `data:` URI) into the Media-Manager `<img>` that Joomla's own editor
     * produces once the image is uploaded.
     *
     * The callback receives the offline-blob id (from the local URL's path,
     * or from the `data-grafida-media-id` tag; null for an untagged data:
     * image that was pasted/dropped directly) together with the raw `data:`
     * URI — null when the image is referenced by id alone, i.e. the local-URL
     * form carries no inline bytes to hand over — and returns the uploaded
     * image's details:
     *   - `src`      the public URL (relative to the site root, as Joomla emits);
     *   - `dataPath` the Media Manager adapter path, e.g. "local-images:/grafida/x.jpg";
     *   - `width` / `height` the intrinsic pixel dimensions (or null if unknown).
     * The `data-grafida-media-id` attribute is dropped, `data-path` (the linkage
     * to the Media Manager entry), `loading="lazy"` and the dimensions are added
     * the way Joomla does. A callback may throw to abort the whole rewrite (e.g.
     * on an upload failure), so a publish never leaves a broken inline image.
     *
     * @param callable(?int $mediaId, ?string $dataUri): array{src: string, dataPath?: ?string, width?: ?int, height?: ?int} $upload
     *
     * @return string The rewritten HTML.
     */
    public function rewriteOfflineImages(string $html, callable $upload): string
    {
        if (trim($html) === '') {
            return $html;
        }

        $dom     = HtmlDocument::load($html);
        $changed = false;

        foreach ($dom->getElementsByTagName('img') as $img) {
            $src = $img->getAttribute('src') ?? '';

            $mediaId = null;
            $dataUri = null;

            if (self::isLocalUrl($src)) {
                // "shell://app/api/media/{id}/raw?rev=…" — the id in the URL is
                // what actually rendered, so it wins over a (stale/absent)
                // data-grafida-media-id attribute when both are present.
                $mediaId = $this->idFromLocalUrl($src);
            } elseif (str_starts_with($src, 'data:')) {
                $idAttr  = $img->getAttribute(self::ATTRIBUTE) ?? '';
                $mediaId = $idAttr !== '' && is_numeric($idAttr) ? (int) $idAttr : null;
                $dataUri = $src;
            } else {
                // A real site URL or an external image — not ours to touch.
                continue;
            }

            $result = $upload($mediaId, $dataUri);

            $this->applyResult($img, $result);

            $changed = true;
        }

        return $changed ? HtmlDocument::innerHtml($dom) : $html;
    }

    /**
     * Rewrites every **legacy inline `data:` image** — and only those — into a
     * `shell://app/api/media/{id}/raw` local-media reference (gh-36's
     * legacy-draft migration: {@see \Grafida\Media\InlineImageExtractor}).
     *
     * Unlike {@see rewriteOfflineImages()} this does **not** touch an `<img>`
     * that already carries a local-media URL: there is nothing to migrate for
     * it, and — critically — the callback contract there has no way to hand
     * back "leave exactly as found" (it only ever sees the offline-blob id,
     * never the src that rendered), which would leave a malformed or
     * otherwise unparsable local URL nowhere to fall back to. Skipping
     * anything that is not `data:` sidesteps that dead end entirely: every
     * `<img>` this method calls the callback for still holds its original
     * `data:` src as `$dataUri`, so a callback that cannot make sense of it
     * can always hand that same string straight back.
     *
     * @param callable(?int $mediaId, string $dataUri): array{src: string, width?: ?int, height?: ?int} $convert
     *        `$mediaId` is the `data-grafida-media-id` tag's value (null when
     *        the image was pasted/dropped directly and never tagged).
     *
     * @return string The rewritten HTML.
     */
    public function rewriteToLocalUrls(string $html, callable $convert): string
    {
        if (trim($html) === '') {
            return $html;
        }

        $dom     = HtmlDocument::load($html);
        $changed = false;

        foreach ($dom->getElementsByTagName('img') as $img) {
            $src = $img->getAttribute('src') ?? '';

            if (!str_starts_with($src, 'data:')) {
                // Already a local-media URL, a real site URL, or an external image —
                // nothing here for the legacy-draft migration to do.
                continue;
            }

            $idAttr  = $img->getAttribute(self::ATTRIBUTE) ?? '';
            $mediaId = $idAttr !== '' && is_numeric($idAttr) ? (int) $idAttr : null;

            $this->applyResult($img, $convert($mediaId, $src));

            $changed = true;
        }

        return $changed ? HtmlDocument::innerHtml($dom) : $html;
    }

    /**
     * Resyncs every `<img>` referencing a specific local media blob after its
     * bytes have changed **in place** (the Local Media tab's crop/resize/
     * rotate/flip editor, gh-43) — the fix for the reported workflow, which
     * edits the blob with the article **closed**: this is what reaches a
     * draft's stored `html` column. {@see \Grafida\Media\LocalMediaSync} finds
     * the affected drafts and persists what this method returns; the
     * live-open-editor counterpart of the same rule is
     * `assets/private/js/editor/localmedia.js`'s `fitDimensions()`.
     *
     * An `<img>` "belongs" to the blob when either its `src` parses (via
     * {@see idFromLocalUrl()}) to `$mediaId`, or its {@see ATTRIBUTE} tag
     * equals it — the same two ways of linking an image to a blob that
     * {@see rewriteOfflineImages()} already tolerates, since the tag can
     * survive a legacy-draft migration or a stale `src` even when one of the
     * two has drifted. Only `src` and — per
     * {@see \Grafida\Media\ImageDimensions::fit()} — the `width`/`height`
     * attributes are ever touched; everything else about the tag (alt text,
     * class, alignment…) is left exactly as the article author set it.
     *
     * Mirrors {@see rewriteToLocalUrls()}'s shape (a plain per-`<img>` walk,
     * the same `$changed` guard so an untouched document is returned
     * byte-identical rather than round-tripped through the DOM serialiser for
     * nothing).
     */
    public function resyncLocalImage(
        string $html,
        int $mediaId,
        string $newSrc,
        ?int $oldW,
        ?int $oldH,
        ?int $newW,
        ?int $newH,
    ): string {
        if (trim($html) === '') {
            return $html;
        }

        $dom     = HtmlDocument::load($html);
        $changed = false;

        foreach ($dom->getElementsByTagName('img') as $img) {
            $src    = $img->getAttribute('src') ?? '';
            $idAttr = $img->getAttribute(self::ATTRIBUTE) ?? '';
            $tagId  = $idAttr !== '' && is_numeric($idAttr) ? (int) $idAttr : null;

            if ($this->idFromLocalUrl($src) !== $mediaId && $tagId !== $mediaId) {
                continue;
            }

            $img->setAttribute('src', $newSrc);

            $attrW = $img->hasAttribute('width') && is_numeric($img->getAttribute('width'))
                ? (int) $img->getAttribute('width')
                : null;
            $attrH = $img->hasAttribute('height') && is_numeric($img->getAttribute('height'))
                ? (int) $img->getAttribute('height')
                : null;

            $fit = ImageDimensions::fit($attrW, $attrH, $oldW, $oldH, $newW, $newH);

            if ($fit !== null) {
                $img->setAttribute('width', (string) $fit['width']);
                $img->setAttribute('height', (string) $fit['height']);
            }

            $changed = true;
        }

        return $changed ? HtmlDocument::innerHtml($dom) : $html;
    }

    /**
     * Applies an upload/convert result to the `<img>` it came from — shared by
     * {@see rewriteOfflineImages()} and {@see rewriteToLocalUrls()}, which
     * differ only in how they decide which images to touch and what the
     * callback is given.
     *
     * @param array{src: string, dataPath?: ?string, width?: ?int, height?: ?int} $result
     */
    private function applyResult(\Dom\Element $img, array $result): void
    {
        $img->setAttribute('src', $result['src']);
        $img->removeAttribute(self::ATTRIBUTE);

        $dataPath = $result['dataPath'] ?? null;
        if (is_string($dataPath) && $dataPath !== '') {
            $img->setAttribute('data-path', $dataPath);
        }

        if (!$img->hasAttribute('loading')) {
            $img->setAttribute('loading', 'lazy');
        }

        $width = $result['width'] ?? null;
        if (is_int($width) && $width > 0 && !$img->hasAttribute('width')) {
            $img->setAttribute('width', (string) $width);
        }

        $height = $result['height'] ?? null;
        if (is_int($height) && $height > 0 && !$img->hasAttribute('height')) {
            $img->setAttribute('height', (string) $height);
        }
    }

    /**
     * Parses the blob id out of a `shell://app/api/media/{id}/raw?rev=…` src,
     * tolerating the `?rev=…` query string (and any other query the kernel is
     * ever given, since it ignores unknown parameters).
     *
     * Public so callers that need to *decide* whether/how to touch a local-URL
     * `<img>` without going through {@see rewriteOfflineImages()}'s upload
     * contract can still reuse this parser rather than re-implementing the
     * regex — {@see \Grafida\Article\DraftExportService::exportHtml()} is the
     * first such caller (gh-36's `.grafida` export, which needs to leave a
     * broken/missing reference exactly as found rather than rewrite it, the
     * same reason {@see rewriteToLocalUrls()} exists).
     */
    public function idFromLocalUrl(string $src): ?int
    {
        $prefix = str_starts_with($src, self::LEGACY_LOCAL_URL_PREFIX) ? self::LEGACY_LOCAL_URL_PREFIX : self::LOCAL_URL_PREFIX;
        $rest   = substr($src, strlen($prefix));

        // "{id}/raw?rev=…" -> id.
        if (preg_match('#^(\d+)/raw(?:\?.*)?$#', $rest, $m) !== 1) {
            return null;
        }

        return (int) $m[1];
    }
}
