<?php

/**
 * Grafida — Joomla content editing, untethered.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Tests\Unit\Media;

use Grafida\Media\ImageDimensions;
use Grafida\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The gh-43 sizing rule. One case per row of .plans/00-overview.md's truth
 * table, plus the "no change needed" case — must stay identical to
 * media/private/js/editor/localmedia.js's fitDimensions()
 * (tests/js/localmedia.test.mjs carries the same cases there).
 */
final class ImageDimensionsTest extends TestCase
{
    /**
     * @return list<array{string, ?int, ?int, ?int, ?int, ?int, ?int, ?array{width: int, height: int}}>
     */
    public static function fitProvider(): array
    {
        return [
            'neither attribute present' => [
                'attrW=null, attrH=null', null, null, 640, 480, 1280, 960, null,
            ],
            'never hand-resized (both attrs match old size) adopts new size wholesale' => [
                'attrW=640, attrH=480', 640, 480, 640, 480, 1280, 960, ['width' => 1280, 'height' => 960],
            ],
            'only width present and matches old width adopts wholesale' => [
                'attrW=640, attrH=null', 640, null, 640, 480, 1280, 960, ['width' => 1280, 'height' => 960],
            ],
            'deliberate in-article size keeps width and re-ratios height' => [
                'attrW=300, attrH=225', 300, 225, 640, 480, 1280, 640, ['width' => 300, 'height' => 150],
            ],
            'matching width but mismatched height is a deliberate resize too' => [
                'attrW=640, attrH=100', 640, 100, 640, 480, 1200, 800, ['width' => 640, 'height' => 427],
            ],
            'width absent, height present keeps height and re-ratios width' => [
                'attrW=null, attrH=240', null, 240, 640, 480, 1280, 960, ['width' => 320, 'height' => 240],
            ],
            'unknown old width leaves untouched' => [
                'oldW=null', 640, 480, null, 480, 1280, 960, null,
            ],
            'zero old height leaves untouched' => [
                'oldH=0', 640, 480, 640, 0, 1280, 960, null,
            ],
            'unknown new width leaves untouched' => [
                'newW=null', 640, 480, 640, 480, null, 960, null,
            ],
            'zero new height leaves untouched' => [
                'newH=0', 640, 480, 640, 480, 1280, 0, null,
            ],
            'no change needed when old and new intrinsic sizes are identical' => [
                'attrW=640, attrH=480', 640, 480, 640, 480, 640, 480, null,
            ],
            'rounds down to a minimum of 1' => [
                'attrW=1, attrH=null', 1, null, 500, 500, 1000000, 1, ['width' => 1, 'height' => 1],
            ],
        ];
    }

    #[DataProvider('fitProvider')]
    public function testFit(
        string $label,
        ?int $attrW,
        ?int $attrH,
        ?int $oldW,
        ?int $oldH,
        ?int $newW,
        ?int $newH,
        ?array $expected,
    ): void {
        self::assertSame($expected, ImageDimensions::fit($attrW, $attrH, $oldW, $oldH, $newW, $newH), $label);
    }
}
