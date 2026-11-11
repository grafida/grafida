<?php

/**
 * Grafida — Joomla content editing, untethered.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Site;

use Grafida\Storage\SettingsRepository;

/**
 * Remembers which site the user last had selected, so it is re-selected on the
 * next launch instead of falling back to the first defined site.
 *
 * This lives server-side (in the generic `settings` key/value store) rather than
 * in the SPA's `localStorage`, because a webview's storage can be cleared without
 * the app's data going with it (and Boson's webview did not persist `localStorage`
 * across an app restart at all) — and a restart is the very moment the preference
 * needs to survive. The SPA still validates the id against the current site list, so a
 * stale id (e.g. a since-deleted site) simply falls back to the first site.
 */
final class LastSiteService
{
    public const SETTING_KEY = 'last_site';

    public function __construct(private readonly SettingsRepository $settings) {}

    /** The remembered site id, or null when none is stored (or it was cleared). */
    public function current(): ?int
    {
        $value = $this->settings->get(self::SETTING_KEY);

        return ($value === null || $value === '') ? null : (int) $value;
    }

    /** Persists (or, given null, clears) the remembered site id. */
    public function set(?int $id): void
    {
        $this->settings->set(self::SETTING_KEY, $id === null ? '' : (string) $id);
    }
}
