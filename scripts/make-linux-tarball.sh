#!/usr/bin/env bash
#
# Grafida — Joomla content editing, untethered.
# Copyright (c) 2026 Nicholas K. Dionysopoulos
# GNU General Public License version 3, or later.
#
# The Linux tarballs (gh-86, gh-92): Grafida on the native shell, built by grafida/shell-packager
# from shell-package.json. Called by `phing package-linux-x86|-arm` and scripts/build-all.sh.
#
# Usage:  scripts/make-linux-tarball.sh [arch ...]   (amd64|arm64; default: both)
#
# Output: build/dist/Grafida-<version>-linux-<arch>.tar.gz (+ .sha256)
#
# The shell, the launcher and the phpmicro SFX are downloaded from the releases pinned in
# shell-package.json and verified by SHA-256 (cached in ~/.cache/grafida-shell-packager). On a Linux
# host of the same architecture the packaged worker is also run before the tarball is written.

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

[ -x vendor/bin/shell-packager ] || { echo "vendor/bin/shell-packager is missing: run composer install." >&2; exit 1; }

# The PHAR carries src/Support/App.php, so stamp App::VERSION from the CHANGELOG first
# (idempotent; GRAFIDA_VERSION overrides).
php build/tasks/set-version.php
VERSION="$(php build/tasks/set-version.php --print)"

# One commit builds one tarball: every timestamp in the PHAR and the archive is the commit's.
export SOURCE_DATE_EPOCH="${SOURCE_DATE_EPOCH:-$(git log -1 --format=%ct)}"

ARCHES=("$@")
[ ${#ARCHES[@]} -gt 0 ] || ARCHES=(amd64 arm64)

for arch in "${ARCHES[@]}"; do
  echo "==> Grafida $VERSION, Linux $arch"
  php vendor/bin/shell-packager linux-tarball --config="$ROOT/shell-package.json" --arch="$arch" --app-version="$VERSION"
done
