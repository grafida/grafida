<?php

/**
 * Grafida — Joomla content editing, untethered.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Tests\Unit\Display;

use Grafida\Display\DisplayModeService;
use Grafida\Secret\ProcessRunner;
use Grafida\Storage\SettingsRepository;
use Grafida\Tests\Support\TestDatabase;
use PHPUnit\Framework\TestCase;

/**
 * The Linux half of "auto": which desktop setting decides light or dark.
 */
final class DisplayModeServiceTest extends TestCase
{
    /**
     * @param array<string, array{0: int, 1: string}> $answers command (space-joined) prefix => [exit code, stdout]
     */
    private static function service(array $answers): DisplayModeService
    {
        $runner = new class ($answers) extends ProcessRunner {
            /** @param array<string, array{0: int, 1: string}> $answers */
            public function __construct(private readonly array $answers)
            {
            }

            public function run(array $command, ?string $stdin = null): array
            {
                $line = implode(' ', $command);

                foreach ($this->answers as $prefix => [$code, $stdout]) {
                    if (str_starts_with($line, $prefix) || str_contains($line, $prefix)) {
                        return [$code, $stdout, ''];
                    }
                }

                return [127, '', 'not found'];
            }
        };

        return new DisplayModeService(new SettingsRepository(TestDatabase::memory()), $runner);
    }

    private const string READ_ONE = 'Settings.ReadOne';

    private const string READ = 'Settings.Read org';

    private const string GNOME_SCHEME = 'gsettings get org.gnome.desktop.interface color-scheme';

    private const string GNOME_THEME = 'gsettings get org.gnome.desktop.interface gtk-theme';

    /** KDE Plasma with Breeze Dark: the portal says dark, GNOME's settings were never touched. */
    public function testThePortalWinsOverGnomesUntouchedSettings(): void
    {
        $service = self::service([
            self::READ_ONE     => [0, "(<uint32 1>,)\n"],
            self::GNOME_SCHEME => [0, "'default'\n"],
            self::GNOME_THEME  => [0, "'Adwaita'\n"],
        ]);

        self::assertTrue($service->linuxPrefersDark());
    }

    public function testThePortalSaysLight(): void
    {
        self::assertFalse(self::service([self::READ_ONE => [0, "(<uint32 2>,)\n"]])->linuxPrefersDark());
    }

    public function testAnOlderPortalOnlyHasRead(): void
    {
        $service = self::service([
            self::READ_ONE => [1, "Error: GDBus.Error:org.freedesktop.DBus.Error.UnknownMethod\n"],
            self::READ     => [0, "(<<uint32 1>>,)\n"],
        ]);

        self::assertTrue($service->linuxPrefersDark());
    }

    public function testNoPreferenceFallsBackToGnome(): void
    {
        $service = self::service([
            self::READ_ONE     => [0, "(<uint32 0>,)\n"],
            self::GNOME_SCHEME => [0, "'prefer-dark'\n"],
        ]);

        self::assertTrue($service->linuxPrefersDark());
    }

    public function testNoPortalAtAllFallsBackToGnome(): void
    {
        self::assertFalse(self::service([self::GNOME_SCHEME => [0, "'default'\n"]])->linuxPrefersDark());
        self::assertTrue(self::service([self::GNOME_THEME => [0, "'Adwaita-dark'\n"]])->linuxPrefersDark());
        self::assertNull(self::service([])->linuxPrefersDark());
    }
}
