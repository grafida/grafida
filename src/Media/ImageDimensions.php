<?php

/**
 * Grafida — Joomla content editing, untethered.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Media;

/**
 * The gh-43 image-sizing rule: what an `<img>`'s `width`/`height` attributes
 * should become when the local blob it references has its bytes replaced in
 * place (the Local Media tab's crop/resize/rotate/flip editor).
 *
 * TinyMCE bakes the *intrinsic* size onto every inserted image (verified in
 * the vendored TinyMCE source: the paste/drop path inserts
 * `<img width="…" height="…">`, and the Insert/Edit Image dialog auto-fills
 * Dimensions from `naturalWidth`/`naturalHeight`). Editing the blob's bytes
 * changes its real dimensions, but nothing revisits those already-baked-in
 * attributes — so a crop leaves the article showing the old picture
 * stretched/squashed into the old aspect ratio. This class decides the fix
 * without ever distorting the picture and without ever silently reverting a
 * hand-picked in-article size:
 *
 * | case                                            | result                              |
 * |--------------------------------------------------|--------------------------------------|
 * | neither attribute present                        | leave untouched (null)              |
 * | width == old width (and height == old height,     | adopt the new intrinsic size        |
 * | when height is present) — i.e. never hand-resized | wholesale                           |
 * | otherwise (a deliberate in-article size)          | keep width, re-ratio the height     |
 * | width absent, height present                      | keep height, re-ratio the width     |
 * | any of old/new width/height unknown (null/0)      | leave untouched (null)              |
 *
 * Implemented twice — here and in `media/private/js/editor/localmedia.js`'s
 * `fitDimensions()` — because the rule applies in two different runtimes: PHP
 * resyncs a *closed* draft's stored `html` column
 * ({@see \Grafida\Html\InlineMedia::resyncLocalImage()}, via
 * {@see \Grafida\Media\LocalMediaSync}), while JS applies the same rule live
 * to an *already-open* editor's DOM. Both implementations must stay
 * byte-for-byte identical (same argument order, same rounding); a change to
 * one without the other is a bug.
 */
final class ImageDimensions
{
    /**
     * @return array{width: int, height: int}|null null when nothing should
     *         change (no attributes to resync, an unknown intrinsic
     *         dimension, or the computed values already match the current
     *         ones)
     */
    public static function fit(?int $attrW, ?int $attrH, ?int $oldW, ?int $oldH, ?int $newW, ?int $newH): ?array
    {
        $aw = self::positive($attrW);
        $ah = self::positive($attrH);

        // Neither attribute present: nothing on the tag to resync.
        if ($aw === null && $ah === null) {
            return null;
        }

        $ow = self::positive($oldW);
        $oh = self::positive($oldH);
        $nw = self::positive($newW);
        $nh = self::positive($newH);

        // Any intrinsic dimension unknown: cannot compute a faithful ratio.
        if ($ow === null || $oh === null || $nw === null || $nh === null) {
            return null;
        }

        if ($aw !== null && $aw === $ow && ($ah === null || $ah === $oh)) {
            // The tag's size still matches the blob's OLD intrinsic size, i.e.
            // it was never hand-resized in the article — adopt the new
            // intrinsic size wholesale.
            $width  = $nw;
            $height = $nh;
        } elseif ($aw !== null) {
            // A deliberate in-article width: keep it, re-ratio the height so
            // the picture is never distorted.
            $width  = $aw;
            $height = max(1, (int) round($aw * $nh / $nw));
        } else {
            // width absent, height present: keep the height, re-ratio the width.
            $height = $ah;
            $width  = max(1, (int) round($ah * $nw / $nh));
        }

        if ($width === $aw && $height === $ah) {
            return null;
        }

        return ['width' => $width, 'height' => $height];
    }

    /** Treats anything not `> 0` (including null) as "absent". */
    private static function positive(?int $value): ?int
    {
        return $value !== null && $value > 0 ? $value : null;
    }
}
