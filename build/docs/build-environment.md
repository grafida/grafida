# Build environment

Release builds are made on a Mac, which builds every platform's package. A Linux system can build the Linux tarballs and the Windows installer; WSL2 on Windows also works, since it's just a Linux virtual machine.

> [!IMPORTANT]
> Building the macOS DMG distributables requires macOS. You cannot do that from Linux.

You need the following directory structure:

- `buildfiles` [Akeeba Build Tools — Public Packager](https://github.com/akeeba/buildfiles-public). Follow its README instructions to initialise it!
- `grafida` [Grafida desktop](https://github.com/grafida/grafida)
- `shell` [Grafida's native shell](https://github.com/grafida/shell). Only needed to run Grafida from source (`composer shell:dev`); packages download a pinned shell release instead.
- `grafida-ipad` [Grafida for iPad and iPhone](https://github.com/grafida/grafida-ipad)
- `grafida-tauri` [Grafida for Android](https://github.com/grafida/grafida-tauri)

> [!NOTE]
> While each Grafida implementation can compile on its own, there are cross-repository references you might need for development purposes.

The following software must be present and available:
- **Bash**. Used for build scripts.
- **PHP** CLI, with the `curl` extension (grafida/shell-packager downloads the pinned shell and PHP runtime releases with it). See the `composer.json` file for the acceptable version range.
- **Composer**, on the PATH. Dependency management; the packager also runs it to stage a `--no-dev` copy of the dependencies for the worker PHAR.
- **Git**. The packaging scripts take the build timestamp from the last commit.
- **Phing**. Follow the Build Tools repo's instructions to install it account-wide.
- **Node.js 16+ and npm 8.5.5+** — composer install automatically runs npm install to vendor and minify the frontend assets
- **Standard Unix utilities** — notably sed, grep, find and sort.

The following software is required on macOS to build DMGs:
- **Apple Command Line Tools** providing codesign, xcrun notarytool and stapler (and iconutil and tiffutil, to regenerate the icons and the DMG background). macOS itself provides hdiutil, spctl, osascript and perl.
- **Rosetta 2**, optional, on an Apple Silicon Mac. With it, the packager also runs the Intel (x86_64) worker before writing its DMG; without it, that check is skipped with a note.

The following software is required to build Windows installers:
- **NSIS** (makensis) 3.08 or later. There is no fallback without it.

Nothing extra is required to build the Linux .tar.gz packages: the packager writes them itself, in PHP.

The following software is required to run Grafida from source (`composer shell:dev`), which builds the sibling `shell` clone:
- **Rust** (cargo), e.g. installed with [rustup](https://rustup.rs).
- On Linux, the **WebKitGTK 4.1 development packages**.

Conditional tools, only relevant to the maintainers / release managers:

- **librsvg** (rsvg-convert) and **ImageMagick** (magick/convert). Only needed to regenerate icons, or the DMG background.
- **Azure CLI** (az), **Jsign**, **osslsigncode** and **1Password CLI** (op). Required only when Windows Authenticode signing is enabled (`windows.sign.op-item` is set); osslsigncode checks every signature the build makes. Jsign also needs a Java runtime, normally installed as its package dependency.
- **OpenSSH** (ssh and sftp), required only for the separate documentation-publishing command, not for a software release. 

## Setting up the build environment on a Mac

```bash
xcode-select --install
brew install node librsvg imagemagick makensis azure-cli jsign osslsigncode 1password-cli

mkdir -p ~/Projects/grafida || exit 1
cd ~/Projects/grafida || exit 1
git clone https://github.com/grafida/grafida.git
git clone https://github.com/grafida/shell.git
git clone https://github.com/grafida/grafida-ipad.git
git clone https://github.com/grafida/grafida-tauri.git
```
