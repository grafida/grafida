## Grafida 0.4

### Highlights

* **Grafida runs on its own native shell.** Up to 0.3 Grafida ran on Boson, a third-party PHP
  desktop runtime. It now runs in a native shell of its own, with the PHP back end as a separate
  worker process. Idle CPU use drops to near 0%, and the window stays responsive while Grafida
  works ([gh-82](https://github.com/grafida/grafida/issues/82)).
* **New system requirements.** macOS 12 Monterey or later (was macOS 15), on Intel Macs as well as
  Apple Silicon; Windows 10 22H2 or later; Linux with WebKitGTK 4.1, version 2.40 or later
  ([gh-92](https://github.com/grafida/grafida/issues/92)).
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
* When Grafida cannot start, its error message now says why first, e.g. that its database could not
  be opened ([gh-92](https://github.com/grafida/grafida/issues/92)).
* The cross-platform `.phar` and the portable Windows `.zip` are no longer published. If you used
  either, switch to your platform's package below.

### Notable fixes

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

See the [`CHANGELOG`](CHANGELOG) for the full list of changes.

### Coming from an earlier version

Your data — sites, drafts, settings and secrets — is untouched on every platform.

**Windows.** Run the new installer. It removes the previous version (and a "shell preview" test
install, if you have one) by itself, and installs in the same place
(`%LOCALAPPDATA%\Programs\Grafida`), keeping your data, settings and taskbar pin.

**macOS.** Drag the new Grafida.app onto Applications, replacing the old one. Your data and the
secrets in your Keychain stay. If you use an AI service on your local network, macOS asks once more
for Local Network access. Pick the download for your Mac: Apple Silicon or Intel.

**Intel Macs.** macOS 27 is the last macOS for Intel Macs. When macOS 28 drops Intel and Rosetta 2,
Grafida for Intel Macs becomes deprecated. When macOS 29 lands it is discontinued: there are no more
Intel builds, and the update check stops offering new releases to Intel Macs.

**Linux.** Extract the tarball and run its `./install.sh`. It replaces the previous version's
desktop entry and icon.

### Downloads

| Platform | Download |
| --- | --- |
| macOS (Apple Silicon) | [`Grafida-0.4-macos-arm64.dmg`](https://github.com/grafida/grafida/releases/download/0.4/Grafida-0.4-macos-arm64.dmg) |
| macOS (Intel) | [`Grafida-0.4-macos-amd64.dmg`](https://github.com/grafida/grafida/releases/download/0.4/Grafida-0.4-macos-amd64.dmg) |
| Windows (x64) | [`Grafida-0.4-windows-amd64-Setup.exe`](https://github.com/grafida/grafida/releases/download/0.4/Grafida-0.4-windows-amd64-Setup.exe) |
| Linux (x64) | [`Grafida-0.4-linux-amd64.tar.gz`](https://github.com/grafida/grafida/releases/download/0.4/Grafida-0.4-linux-amd64.tar.gz) |
| Linux (ARM64) | [`Grafida-0.4-linux-arm64.tar.gz`](https://github.com/grafida/grafida/releases/download/0.4/Grafida-0.4-linux-arm64.tar.gz) |

Each file has a `.sha256` file beside it, with the same name plus `.sha256`, holding its SHA-256
checksum.
