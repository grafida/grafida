#!/usr/bin/env bash
#
# Grafida — Joomla content editing, untethered.
# Copyright (c) 2026 Nicholas K. Dionysopoulos
# GNU General Public License version 3, or later.
#
# EXPERIMENTAL: the Linux tarball for the native shell (gh-86, Phase 3 of gh-82), built by
# grafida/shell-packager from shell-package.json. It is NOT part of `composer build`,
# `phing package` or `phing release`, which keep shipping Boson until the switch-over: this only
# runs when asked for (`composer build:shell-linux`, `phing package-shell-linux-x86|-arm`).
#
# Usage:  scripts/make-shell-linux-tarball.sh [arch ...]   (amd64|arm64; default: both)
#
# Output: build/dist/Grafida-<version>-linux-<arch>-shell-preview.tar.gz (+ .sha256)
#
# The shell, the launcher and the phpmicro SFX are downloaded from the releases pinned in
# shell-package.json and verified by SHA-256 (cached in ~/.cache/grafida-shell-packager — never in
# build/sfx/, which compile-target.php would inject into the Boson build). On a Linux host of the
# same architecture the packaged worker is also run before the tarball is written.

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

[ -x vendor/bin/shell-packager ] || { echo "vendor/bin/shell-packager is missing: run composer install." >&2; exit 1; }

# The PHAR carries src/Support/App.php, so stamp App::VERSION from the CHANGELOG first, as every
# Boson compile does (idempotent; GRAFIDA_VERSION overrides).
php build/tasks/set-version.php
VERSION="$(php build/tasks/set-version.php --print)"

# One commit builds one tarball: every timestamp in the PHAR and the archive is the commit's.
export SOURCE_DATE_EPOCH="${SOURCE_DATE_EPOCH:-$(git log -1 --format=%ct)}"

ARCHES=("$@")
[ ${#ARCHES[@]} -gt 0 ] || ARCHES=(amd64 arm64)

for arch in "${ARCHES[@]}"; do
  echo "==> Grafida $VERSION, Linux $arch, native shell (preview)"
  php vendor/bin/shell-packager linux-tarball --config="$ROOT/shell-package.json" --arch="$arch" --app-version="$VERSION"
done
