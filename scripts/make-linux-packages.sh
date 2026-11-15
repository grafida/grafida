#!/usr/bin/env bash
#
# Grafida — Joomla content editing, untethered.
# Copyright (c) 2026 Nicholas K. Dionysopoulos
# GNU General Public License version 3, or later.
#
# The Linux distribution packages (gh-87): .deb, .rpm and AppImage per architecture, and the Arch
# PKGBUILD for the tarballs. Built by grafida/shell-packager from shell-package.json. All of them
# run on the system's WebKitGTK 4.1 and GTK 3; none bundles them.
#
# Usage:  scripts/make-linux-packages.sh [arch ...]   (amd64|arm64; default: both)
#
# Output: build/dist/Grafida-<version>-linux-<debarch>.deb, -linux-<arch>.rpm, -linux-<arch>.AppImage
#         (each + .sha256) and build/dist/PKGBUILD.
#
# Needs `mksquashfs` for the AppImage (Homebrew `squashfs`, apt `squashfs-tools`). nfpm is fetched
# and verified by the packager. The PKGBUILD names the tarballs, so they are built first (this
# script does that), and its source is the GitHub release the tarballs are uploaded to.

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

[ -x vendor/bin/shell-packager ] || { echo "vendor/bin/shell-packager is missing: run composer install." >&2; exit 1; }
command -v mksquashfs >/dev/null || { echo "mksquashfs is missing (Homebrew: squashfs; apt: squashfs-tools)." >&2; exit 1; }

php build/tasks/set-version.php
VERSION="$(php build/tasks/set-version.php --print)"

# One commit builds one set of packages: every timestamp is the commit's.
export SOURCE_DATE_EPOCH="${SOURCE_DATE_EPOCH:-$(git log -1 --format=%ct)}"

ARCHES=("$@")
[ ${#ARCHES[@]} -gt 0 ] || ARCHES=(amd64 arm64)

PACKAGER=(php vendor/bin/shell-packager)
for arch in "${ARCHES[@]}"; do
  echo "==> Grafida $VERSION, Linux $arch"
  "${PACKAGER[@]}" linux-tarball  --config="$ROOT/shell-package.json" --arch="$arch" --app-version="$VERSION"
  "${PACKAGER[@]}" linux-deb      --config="$ROOT/shell-package.json" --arch="$arch" --app-version="$VERSION"
  "${PACKAGER[@]}" linux-rpm      --config="$ROOT/shell-package.json" --arch="$arch" --app-version="$VERSION"
  "${PACKAGER[@]}" linux-appimage --config="$ROOT/shell-package.json" --arch="$arch" --app-version="$VERSION"
done

echo "==> Grafida $VERSION, Arch PKGBUILD"
pkgbuild_args=()
for arch in "${ARCHES[@]}"; do pkgbuild_args+=("--arch=$arch"); done
"${PACKAGER[@]}" linux-pkgbuild --config="$ROOT/shell-package.json" --app-version="$VERSION" "${pkgbuild_args[@]}"
