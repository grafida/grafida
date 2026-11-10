#!/usr/bin/env bash
#
# Grafida — Joomla content editing, untethered.
# Copyright (c) 2026 Nicholas K. Dionysopoulos
# GNU General Public License version 3, or later.
#
# EXPERIMENTAL: the macOS (Apple Silicon) disk image for the native shell (gh-88, Phase 4 of
# gh-82), built by grafida/shell-packager from shell-package.json. It is NOT part of
# `composer build`, `phing package` or `phing release`, which keep shipping Boson until the
# switch-over: this only runs when asked for (`composer build:shell-macos`,
# `phing package-shell-macos-arm`).
#
# Usage:  scripts/make-shell-macos-dmg.sh [shell-packager options, e.g. --no-style]
#
# Output: build/dist/Grafida-<version>-macos-arm64-shell-preview.dmg (+ .sha256), and the bundle
#         in build/shell-macos/Grafida.app
#
# The shell and the phpmicro SFX are downloaded from the releases pinned in shell-package.json and
# verified by SHA-256 (cached in ~/.cache/grafida-shell-packager — never in build/sfx/, which
# compile-target.php would inject into the Boson build). The packaged worker is run inside the
# signed bundle before the image is written.
#
# Signing comes from the environment, the same variables the Boson scripts read:
#   MACOS_SIGN_IDENTITY   a "Developer ID Application" identity; without it, ad hoc
#   MACOS_NOTARY_PROFILE  a notarytool keychain profile; with the identity, the image is notarised
# The Phing target fills both from build/build.properties.

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

[ "$(uname -s)" = Darwin ] || { echo "The macOS bundle and image are built on a Mac." >&2; exit 1; }
[ -x vendor/bin/shell-packager ] || { echo "vendor/bin/shell-packager is missing: run composer install." >&2; exit 1; }

# The PHAR carries src/Support/App.php, so stamp App::VERSION from the CHANGELOG first, as every
# Boson compile does (idempotent; GRAFIDA_VERSION overrides).
php build/tasks/set-version.php
VERSION="$(php build/tasks/set-version.php --print)"

# The PHAR inside is the same for one commit (macOS signatures and images are not reproducible).
export SOURCE_DATE_EPOCH="${SOURCE_DATE_EPOCH:-$(git log -1 --format=%ct)}"

echo "==> Grafida $VERSION, macOS arm64, native shell (preview), signed: ${MACOS_SIGN_IDENTITY:-ad hoc}"
APP="$ROOT/build/shell-macos/Grafida.app"
php vendor/bin/shell-packager macos-app --config="$ROOT/shell-package.json" --app-version="$VERSION" --output="$APP"
php vendor/bin/shell-packager macos-dmg --config="$ROOT/shell-package.json" --app-version="$VERSION" --app="$APP" "$@"
