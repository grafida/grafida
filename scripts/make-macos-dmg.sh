#!/usr/bin/env bash
#
# Grafida — Joomla content editing, untethered.
# Copyright (c) 2026 Nicholas K. Dionysopoulos
# GNU General Public License version 3, or later.
#
# The macOS disk images (gh-88, gh-92): Grafida.app on the native shell, built by
# grafida/shell-packager from shell-package.json, one per architecture: arm64 (Apple Silicon) and
# amd64 (x86_64, for Intel Macs, tested under Rosetta 2 only). Called by `phing package-macos-arm`,
# `phing package-macos-x86` and scripts/build-all.sh.
#
# Usage:  scripts/make-macos-dmg.sh [arm64|amd64 …] [-- shell-packager options, e.g. --no-style]
#         (no architecture: both)
#
# Output: build/dist/Grafida-<version>-macos-<arm64|amd64>.dmg (+ .sha256), and the bundles in
#         build/shell-macos/<arch>/Grafida.app
#
# The shell and the phpmicro SFX are downloaded from the releases pinned in shell-package.json and
# verified by SHA-256 (cached in ~/.cache/grafida-shell-packager). The packaged worker is run inside
# the signed bundle before the image is written: natively for arm64, under Rosetta 2 for x86_64 when
# this Mac has it (otherwise shell-packager says it skipped the check).
#
# Signing comes from the environment:
#   MACOS_SIGN_IDENTITY   a "Developer ID Application" identity; without it, ad hoc
#   MACOS_NOTARY_PROFILE  a notarytool keychain profile; with the identity, the image is notarised
# The Phing targets fill both from build/build.properties.

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

[ "$(uname -s)" = Darwin ] || { echo "The macOS bundles and images are built on a Mac." >&2; exit 1; }
[ -x vendor/bin/shell-packager ] || { echo "vendor/bin/shell-packager is missing: run composer install." >&2; exit 1; }

ARCHES=()
while [ $# -gt 0 ] && [ "$1" != "--" ]; do
  case "$1" in
    arm64|amd64) ARCHES+=("$1") ;;
    *) echo "Unknown architecture '$1' (arm64 or amd64)." >&2; exit 1 ;;
  esac
  shift
done
[ "${1:-}" = "--" ] && shift
[ ${#ARCHES[@]} -gt 0 ] || ARCHES=(arm64 amd64)

# The PHAR carries src/Support/App.php, so stamp App::VERSION from the CHANGELOG first
# (idempotent; GRAFIDA_VERSION overrides).
php build/tasks/set-version.php
VERSION="$(php build/tasks/set-version.php --print)"

# The PHAR inside is the same for one commit (macOS signatures and images are not reproducible).
export SOURCE_DATE_EPOCH="${SOURCE_DATE_EPOCH:-$(git log -1 --format=%ct)}"

for arch in "${ARCHES[@]}"; do
  echo "==> Grafida $VERSION, macOS $arch, signed: ${MACOS_SIGN_IDENTITY:-ad hoc}"
  APP="$ROOT/build/shell-macos/$arch/Grafida.app"
  php vendor/bin/shell-packager macos-app --config="$ROOT/shell-package.json" --arch="$arch" --app-version="$VERSION" --output="$APP"
  php vendor/bin/shell-packager macos-dmg --config="$ROOT/shell-package.json" --arch="$arch" --app-version="$VERSION" --app="$APP" "$@"
done
