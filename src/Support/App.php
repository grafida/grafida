<?php

/**
 * Grafida — Joomla content editing, untethered.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Support;

/**
 * Single source of truth for the application's identity and legal metadata.
 *
 * These values are surfaced verbatim in the About dialog. The copyright,
 * licence and the Joomla! trademark disclaimer are legal text and must never
 * be translated, so they live here as constants (not in the language files).
 */
final class App
{
    /** Human-readable application name. */
    public const NAME = 'Grafida';

    /** Application version (semantic versioning). */
    public const VERSION = '0.4';

    /** Copyright line. */
    public const COPYRIGHT = 'Copyright © 2026 Nicholas K. Dionysopoulos';

    /** Canonical author biography URL. */
    public const AUTHOR_URL = 'https://www.dionysopoulos.me/about-me';

    /** Licence name. */
    public const LICENSE = 'GNU General Public License version 3, or later';

    /** Canonical URL of the full licence text on the FSF's website. */
    public const LICENSE_URL = 'https://www.gnu.org/licenses/gpl-3.0.html';

    /**
     * Public Holopin verification page of the J!Awards 2026 finalist badge shown in the About
     * dialog. The badge image itself is bundled (`media/public/img/jawards-2026-finalist.webp`) so
     * opening the dialog never contacts Holopin.
     */
    public const AWARD_URL = 'https://www.holopin.io/userbadge/cmw17ole50008lb08oopvs73l';

    /**
     * Joomla! trademark disclaimer. Required, must be displayed verbatim, and
     * must never be translated.
     */
    public const JOOMLA_DISCLAIMER = 'This application is not affiliated with or endorsed by the Joomla! Project. It is not supported or warranted by the Joomla! Project or Open Source Matters. The Joomla! logo is used under a limited license granted by Open Source Matters, the trademark holder in the United States and other countries.';

    /**
     * The metadata payload sent to the front-end at start-up.
     *
     * @return array{name: string, version: string, copyright: string, authorUrl: string, license: string, licenseUrl: string, awardUrl: string, disclaimer: string}
     */
    public static function info(): array
    {
        return [
            'name'       => self::NAME,
            'version'    => self::VERSION,
            'copyright'  => self::COPYRIGHT,
            'authorUrl'  => self::AUTHOR_URL,
            'license'    => self::LICENSE,
            'licenseUrl' => self::LICENSE_URL,
            'awardUrl'   => self::AWARD_URL,
            'disclaimer' => self::JOOMLA_DISCLAIMER,
        ];
    }
}
