## Grafida 0.4

### Highlights

* **Grafida runs on its own native shell.** Up to 0.3 Grafida ran on Boson, a third-party PHP
  desktop runtime. It now runs in a native shell of its own, with the PHP back end as a separate
  worker process. Idle CPU use drops to near 0%, and the window stays responsive while Grafida
  works ([gh-82](https://github.com/grafida/grafida/issues/82)).
* **New system requirements, lower than before.** macOS 12 Monterey or later (was macOS 15), on
  Intel Macs as well as Apple Silicon; Windows 10 22H2 or later; Linux with GTK 3 and WebKitGTK 4.1,
  version 2.40 or later (was GTK 4.10 and WebKitGTK 6.0), so Grafida now runs on Ubuntu 22.04 and
  Debian 12 ([gh-92](https://github.com/grafida/grafida/issues/92)).
* **macOS: a real menu bar**, with Edit and Spelling menus. About opens Grafida's own dialog, and
  Edit ▸ Undo follows the editor ([gh-88](https://github.com/grafida/grafida/issues/88)).
* **Native file dialogs.** Files and folders are picked in the operating system's own dialogs
  ([gh-85](https://github.com/grafida/grafida/issues/85)).
* **The update check only offers a release your computer can run**: one that supports its operating
  system, OS version and processor ([gh-92](https://github.com/grafida/grafida/issues/92)).
* **Distraction-free mode** in the article editor hides the sidebars, toolbar and title, leaving only
  the editor.
* **Settings is split into tabs**: General, Editor, AI Assistant and Advanced. Long explanations fold
  away.
* **Windows: the installer upgrades in place**, keeping your data, settings and taskbar pin. The
  installer and the uninstaller ask you to close a running Grafida instead of failing or leaving
  files behind ([gh-91](https://github.com/grafida/grafida/issues/91)).
* **Linux: `install.sh` replaces the previous version's desktop entry and icon**
  ([gh-92](https://github.com/grafida/grafida/issues/92)).
* When Grafida cannot start, its error message now says why first, e.g. that it cannot write to its
  data folder ([gh-92](https://github.com/grafida/grafida/issues/92)).
* The cross-platform `.phar` and the portable Windows `.zip` are no longer published. If you used
  either, switch to your platform's download.

### Notable fixes

* The update check never offered a newer version. Every earlier version is now told about this
  one, and later ones ([gh-92](https://github.com/grafida/grafida/issues/92)).
* **Linux:** the app crashed on the first click that saved anything, e.g. saving a site or switching
  the theme ([gh-79](https://github.com/grafida/grafida/issues/79)).
* Clicking a sidebar menu item while editing an article discarded unsaved changes without asking
  ([gh-80](https://github.com/grafida/grafida/issues/80)).
* Publishing an article with a tag that did not exist on the site yet failed with "Invalid field:
  Parent".
* Users without Edit State, such as Authors, got HTTP 500 updating an article that was ever
  published.
* **Linux:** the Automatic display mode ignored KDE Plasma's dark theme.
* The Automatic display mode missed an OS theme change while you were typing in the editor, or while
  Grafida was in the background.
* Changing an AI tool, such as its AI service, only took effect after restarting Grafida; and a tool
  pointed at a specific AI service could not be switched back to the default one.
* Leaving the article did not stop an AI reply in progress.
* The Articles screen kept a hidden loading spinner animating, using CPU while idle
  ([gh-86](https://github.com/grafida/grafida/issues/86)).

### Coming from an earlier version

Your data — sites, drafts, settings and secrets — is untouched on every platform.

**Windows.** Run the new installer. It removes the previous version (and a "shell preview" test
install, if you have one) by itself, and installs in the same place
(`%LOCALAPPDATA%\Programs\Grafida`), keeping your data, settings and taskbar pin.

**macOS.** Drag the new Grafida.app onto Applications, replacing the old one. Your data and the
secrets in your Keychain stay. If you use an AI service on your local network, macOS may ask once
more for Local Network access. Pick the download for your Mac: Apple Silicon or Intel.

**Intel Macs.** macOS 27 is the last macOS for Intel Macs. When macOS 28 drops Intel and Rosetta 2,
Grafida for Intel Macs becomes deprecated. When macOS 29 lands it is discontinued: there are no more
Intel builds, and the update check stops offering new releases to Intel Macs.

**Linux.** Grafida 0.4 needs different system libraries from 0.3. Up to 0.3 it needed GTK 4.10 or
later and WebKitGTK 6.0, which ruled out long-term-support distributions such as Ubuntu 22.04. It
now needs GTK 3 and WebKitGTK 4.1 (2.40 or later), which are older and more widely available. Your
distribution may not have them installed yet, even if 0.3 ran on it. Before you start 0.4, read the
[Requirements](https://grafida.app/docs/desktop/Requirements.html) page and install the packages it
lists for your distribution. Among them is `secret-tool`, which Ubuntu and Debian do not install by
default, and which Grafida needs to keep your tokens in your desktop's secret store.

Then delete the folder you extracted 0.3 into, or extract 0.4 into a new one: 0.3's runtime files
are not used any more, and your data is not kept there. Extract the tarball and run its
`./install.sh`. It replaces the previous version's desktop entry and icon. A favourite or
task-manager pin you made for 0.3 stops working, so pin Grafida again after the upgrade.

### CHANGELOG

* ‼️ Grafida runs on its own native shell instead of Boson: ~0% CPU when idle, and the window never freezes (gh-82)
* ✨ Distraction-free mode in the article editor: hides the sidebars, toolbar and title so only the editor remains
* ✨ The update check no longer offers a release that does not support this computer's OS, processor or OS version (gh-92)
* ✨ Intel Macs: a macOS x86_64 build (macOS 12 or later), until Apple drops Intel (gh-92)
* ✨ macOS: a menu bar with Edit and Spelling menus; About opens Grafida's own dialog, Undo follows the editor (gh-88)
* ✨ Windows: the installer upgrades Boson's Grafida in place, keeping your data, settings and taskbar pin (gh-92)
* ✨ Linux: `install.sh` replaces the previous version's desktop entry and icon (gh-92)
* ✂️ The cross-platform `.phar` and the portable Windows `.zip` are no longer published (gh-92)
* ✏️ Runs on macOS 12 or later (was macOS 15), Windows 10 22H2 or later, and Linux with WebKitGTK 4.1 2.40 or later (gh-92)
* ✏️ Windows: the installer and the uninstaller ask to close a running Grafida instead of failing or leaving files (gh-91)
* ✏️ Files and folders are picked in the operating system's own dialogs, opened by the native shell (gh-85)
* ✏️ Phing brings the Composer dependencies in line with composer.lock before every build, not only when vendor/ is missing
* ✏️ Windows signing defaults to the akeeba-signing/AkeebaPublic profile; the signing notes say what to check on a 403
* ✏️ Settings is split into General, Editor, AI Assistant and Advanced tabs; long explanations fold away
* ✏️ Connection test no longer tries the never-valid /index.php/api URL
* ✏️ The Windows build no longer enables opcache, matching every other platform
* ✏️ Documentation: how Authors can use Grafida on Joomla 5.4.8/6.1.3+ without back-end access
* ✏️ Documentation: Linux needs a Hunspell dictionary per language for spell checking
* ✏️ The documentation is published to Grafida.app over SFTP, replacing the copy into a sibling site repository
* ✏️ The patched PHP runtime is fetched from grafida/phpmicro (moved from nikosdion/phpmicro)
* ✏️ When Grafida cannot start, its error says why first, e.g. that it cannot write to its data folder (gh-92)
* 🐞 [HIGH] The update check never offered a newer version: the update information gave it as "Grafida 0.3" (gh-92)
* 🐞 [HIGH] Linux: the app crashed on the first click that saved anything, e.g. saving a site or switching theme (gh-79)
* 🐞 [HIGH] Clicking a sidebar menu item while editing an article discarded unsaved changes without asking (gh-80)
* 🐞 [HIGH] Publishing an article with a tag that did not exist on the site yet failed with "Invalid field: Parent"
* 🐞 [MEDIUM] Users without Edit State, such as Authors, got HTTP 500 updating an article that was ever published
* 🐞 [MEDIUM] Linux: the Automatic display mode ignored KDE Plasma's dark theme
* 🐞 [MEDIUM] The Automatic display mode missed an OS theme change while typing in the editor or in the background
* 🐞 [MEDIUM] Changing an AI tool, such as its AI service, only took effect after restarting Grafida
* 🐞 [MEDIUM] An AI tool pointed at a specific AI service could not be switched back to the default one
* 🐞 [MEDIUM] Leaving the article did not stop an AI reply in progress
* 🐞 [MEDIUM] The Articles screen kept a hidden loading spinner animating, using CPU while idle (gh-86)
* 🐞 [LOW] An upgrade could keep an outdated translation or migration, and a removed language stayed selectable (gh-86)

Legend:
* 🚨 Security update
* ‼️ Important change
* ✨ New feature
* ✂️ Removed feature
* ✏️ Miscellaneous change
* 🐞 Bug fix
