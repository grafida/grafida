# Building from source

Grafida needs **PHP 8.4+** with the `ffi`, `pdo_sqlite`, `dom`, `mbstring` and `curl`
extensions, plus [Composer](https://getcomposer.org), [Node.js + npm](https://nodejs.org)
(the front-end libraries are vendored via npm, not committed) and
[Phing](https://www.phing.info) installed as a **global** command.

```bash
git clone https://github.com/grafida/grafida.git
cd grafida
composer install                     # also vendors the front-end libraries via npm

# Run Grafida from this source tree, in the native shell's dev mode:
composer shell:dev                   # or: composer start, phing run

# One step: package every platform's distributable.
composer build                       # artifacts land in build/dist/
```

**Dev mode** (`composer shell:dev`) builds the native shell from a sibling clone of
[grafida/shell](https://github.com/grafida/shell) in `../shell`, then starts it with `--dev .`,
so the worker is your system's `php bin/worker.php` and a PHP edit takes effect when the worker
restarts. Nothing is packaged. It needs a Rust toolchain, and on Linux the WebKitGTK 4.1
development packages. Add `-- --automation` to open the shell's debug automation channel.

**Packaging** is done by [grafida/shell-packager](https://github.com/grafida/shell-packager), a
development dependency, which reads [`shell-package.json`](../../shell-package.json) and the app manifest
[`shell.json`](../../shell.json). There is no compile step. For each package it:

- builds the worker PHAR (`bin/worker.php`, `src/`, the SPA under `assets/`, `docs/`, `language/`,
  the SQL migrations and the PHP files of a `composer install --no-dev` staging copy, so no
  development dependency ships);
- downloads the native shell (and, on Linux, its launcher) and the phpmicro runtime that runs the
  worker, from the releases pinned in `shell-package.json`, and verifies each against its pinned
  SHA-256 (downloads are cached in `~/.cache/grafida-shell-packager`);
- assembles, signs and wraps them into the distributable, with a `.sha256` beside it.

Everything is built **on a Mac**: the macOS bundles need `codesign`, `notarytool` and `hdiutil`, and
the Windows installer's tools (`makensis`, Jsign, `osslsigncode`) run there too. The Linux tarballs
and the Windows installer can also be built on Linux.

The build is driven by [`build.xml`](../../build.xml) (Phing) alongside the one-shot
`scripts/build-all.sh` pipeline. Both call the same per-platform `scripts/make-*.sh` helpers, so they
produce the same artifacts in **`build/dist/`**:

```bash
phing package                        # package every platform (the default target; also: phing all)
phing package-macos-arm              # …or one platform: package-macos-x86, package-windows-x86,
phing package-linux-x86              #    package-linux-x86, package-linux-arm
```

| Platform | Artifact | Phing target | Packaged by |
| --- | --- | --- | --- |
| macOS (Apple Silicon) | `Grafida-<version>-macos-arm64.dmg` | `package-macos-arm` | `scripts/make-macos-dmg.sh arm64` *(macOS host only)* |
| macOS (Intel) | `Grafida-<version>-macos-amd64.dmg` | `package-macos-x86` | `scripts/make-macos-dmg.sh amd64` *(macOS host only)* |
| Windows (x64) | `Grafida-<version>-windows-amd64-Setup.exe` | `package-windows-x86` | `scripts/make-windows-installer.sh` |
| Linux (x64) | `Grafida-<version>-linux-amd64.tar.gz` | `package-linux-x86` | `scripts/make-linux-tarball.sh amd64` |
| Linux (ARM64) | `Grafida-<version>-linux-arm64.tar.gz` | `package-linux-arm` | `scripts/make-linux-tarball.sh arm64` |

Equivalent Composer shortcuts: `composer build` (`scripts/build-all.sh`, every platform; one
platform failing does not stop the others), `composer build:package` (`phing package`), and one
platform at a time with `composer build:macos`, `composer build:linux` and `composer build:windows`.
`build:macos` and `build:linux` take an architecture to build just one, e.g.
`composer build:linux -- amd64`; `composer build:macos -- arm64 -- --no-style` also skips the
Finder layout of the disk image.

The difference between the two entry points is where the signing settings come from:
`phing package` reads them from `build/build.properties`, while `composer build` and the
`composer build:*` scripts read them from the environment. See [Code signing](#code-signing) below.

What each package holds:

- **macOS**: a disk image with `Grafida.app` and an `/Applications` link. The bundle carries the
  shell as `Contents/MacOS/Grafida`, the worker stub `Contents/MacOS/grafida-worker` beside it, and
  `grafida-worker.phar`, `shell.json`, the icon and the licence in `Contents/Resources`. Its minimum
  macOS is 12.0.
- **Linux**: a `grafida/` folder with the launcher (`grafida`), the shell (`grafida-shell`), the
  worker stub (`grafida-worker`) and `grafida-worker.phar`, `shell.json`, the desktop entry, the
  icons and `install.sh`, which installs the desktop entry and icons for the current user.
- **Windows**: a per-user NSIS installer, built from
  [`build/shell-windows-installer.nsi`](../../build/shell-windows-installer.nsi), which installs
  `Grafida.exe` (the shell), `grafida-worker.exe` and `grafida-worker.phar` into
  `%LOCALAPPDATA%\Programs\Grafida`. It needs NSIS's `makensis` 3.08 or later, which runs natively
  on macOS and Linux (`brew install makensis`); there is no portable `.zip` fallback.

The worker is a phpmicro self-executable stub with its PHAR kept as a **separate sibling file**.
That split is what makes signing possible: signing a stub with the PHAR appended to it would put the
signature past the PHAR, corrupt it, and the app would die at startup.

**The application version is the topmost entry of the [`CHANGELOG`](../../CHANGELOG)** (e.g. `Grafida
0.4`). Every packaging script stamps it into `App::VERSION` first, so the package and the About
dialog report it; set `GRAFIDA_VERSION=…` to override the CHANGELOG. The scripts also set
`SOURCE_DATE_EPOCH` to the commit time, so one commit builds the same PHAR (and the same Linux
tarball, byte for byte).

The bundled language files and SQL migrations are copied out of the PHAR into the application data
directory at every launch, because `parse_ini_file()`/`glob()` cannot read from inside a PHAR.

## Code signing

Without signing settings, the macOS bundles are signed ad hoc (still with the hardened runtime),
which is enough to run the app on the build machine, and the Windows installer is unsigned.

- **macOS**: set `MACOS_SIGN_IDENTITY` (a "Developer ID Application" identity) to sign, and
  `MACOS_NOTARY_PROFILE` (a `notarytool` keychain profile) as well to notarise and staple the disk
  image. For Phing, set `macos.sign.identity` and `macos.notary.profile` in `build/build.properties`.
- **Windows**: Azure Trusted Signing through Jsign, switched on by `windows.sign.op-item` alone (the
  1Password item holding the service principal; `WINDOWS_SIGN_OP_ITEM` for `composer build` and
  `composer build:windows`). When it is empty, nothing is signed. When it is set, the build signs the
  shell, the worker stub, the uninstaller and the installer — four signatures a build — with the
  other `windows.sign.*` properties.

The full recipes are in [`build/readme/01-macos-signing.md`](../../build/readme/01-macos-signing.md)
(macOS) and [`build/readme/04-exe-signing-on-macos.md`](../../build/readme/04-exe-signing-on-macos.md)
(Windows).

## Application icons

The application icon (a pencil drawing a capital “J”) lives as a single master SVG at
`assets/logo/grafida.svg`. Regenerate every per-platform format from it with:

```bash
scripts/make-icons.sh
```

This writes `assets/logo/Grafida.icns` (macOS), `assets/logo/Grafida.ico` (Windows) and a PNG
set under `assets/logo/png/` (Linux), plus a 512px `assets/logo/grafida.png`. The generated
files are committed, so you only need to re-run this after editing the SVG.

- **macOS** — shell-packager puts `Grafida.icns` into the bundle and its `Info.plist`, and uses it
  as the disk image's volume icon.
- **Windows** — shell-packager writes `Grafida.ico` and a version resource into `Grafida.exe` and
  `grafida-worker.exe` itself, in PHP (no `rcedit`), and the installer uses it for its shortcuts.
- **Linux** — the tarball carries the PNG set as hicolor icons and the desktop entry built from
  `assets/logo/grafida.desktop`; its `install.sh` installs both for the current user.

## Documentation

The Markdown pages under `docs/` are both the in-app Help screen and the Documentation section of
[Grafida.app](https://grafida.app). Publish the site copy with:

```bash
composer docs:publish -- --dry-run   # show what would be sent, connect to nothing
composer docs:publish                # upload over SFTP
```

This is deliberately not a release step: the app serves Help out of its own binary, so a corrected
page is worth publishing the day it is committed. Configure the destination with the `docs.sftp.*`
properties in `build/build.properties` — see
[`build/readme/05-documentation-publishing.md`](../../build/readme/05-documentation-publishing.md).
