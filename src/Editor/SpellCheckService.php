<?php

/**
 * Grafida — Joomla content editing, untethered.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Editor;

use Grafida\Storage\SettingsRepository;

/**
 * Stores whether the editor's native spell checking is enabled.
 *
 * On by default. The authoritative on/off control is the editing body's
 * `spellcheck` attribute (driven by TinyMCE's `browser_spellcheck`): WebKit will
 * not check an element with `spellcheck="false"` even when its global continuous-
 * checking flag is on, so this preference alone turns the underlining off on every
 * platform, live. The macOS master flag (`WebContinuousSpellCheckingEnabled`) is set
 * on by the shell, in process, before it creates the webview, so the attribute can
 * take effect (gh-24).
 */
final class SpellCheckService
{
    public const SETTING_KEY = 'spell_check';

    public function __construct(private readonly SettingsRepository $settings) {}

    /** The stored preference, defaulting to enabled. */
    public function current(): bool
    {
        return ($this->settings->get(self::SETTING_KEY, '1') ?? '1') !== '0';
    }

    /** Persists and returns the preference. */
    public function set(bool $enabled): bool
    {
        $this->settings->set(self::SETTING_KEY, $enabled ? '1' : '0');

        return $enabled;
    }
}
