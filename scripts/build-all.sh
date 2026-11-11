#!/usr/bin/env bash
# ============================================================================
# Grafida — package everything, for every supported platform.
# Joomla content editing, untethered.
#
# Copyright (c) 2026 Nicholas K. Dionysopoulos
# License: GNU General Public License version 3, or later
#
# The one-shot pipeline behind `composer build` (gh-92). It vendors the
# front-end libraries, then builds every distributable into build/dist/ with
# grafida/shell-packager, through the same scripts the Phing package-* targets
# call:
#
#   - macOS  : a .dmg per architecture, arm64 and amd64 (on a Mac only)
#              scripts/make-macos-dmg.sh
#   - Linux  : a .tar.gz per architecture, amd64 and arm64
#              scripts/make-linux-tarball.sh
#   - Windows: the x64 NSIS installer (makensis, on a Mac or Linux)
#              scripts/make-windows-installer.sh
#
# Each artefact gets a .sha256 beside it. Signing comes from the environment
# (MACOS_SIGN_IDENTITY / MACOS_NOTARY_PROFILE, WINDOWS_SIGN_OP_ITEM and the
# rest of WINDOWS_SIGN_*); without it the macOS bundles are signed ad hoc and
# the Windows installer is unsigned. `phing package` fills them from
# build/build.properties instead.
#
# One platform failing does not stop the others; the summary at the end names
# every failure and the exit status is 1 if there was any.
# ============================================================================

set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(dirname "$SCRIPT_DIR")"
cd "$ROOT" || exit 1

[ -x vendor/bin/shell-packager ] || { echo "vendor/bin/shell-packager is missing: run composer install." >&2; exit 1; }

echo "==> Vendoring the front-end libraries"
composer run-script vendor:assets || { echo "vendor:assets failed" >&2; exit 1; }

FAILED=()
run() { # label, command…
  local label=$1
  shift
  echo
  echo "################ $label"
  if "$@"; then
    echo "################ $label: done"
  else
    echo "################ $label: FAILED" >&2
    FAILED+=("$label")
  fi
}

if [ "$(uname -s)" = Darwin ]; then
  run "macOS arm64" bash scripts/make-macos-dmg.sh arm64
  run "macOS amd64" bash scripts/make-macos-dmg.sh amd64
else
  echo "Skipping macOS: its bundles are built and signed on a Mac."
fi

run "Linux amd64" bash scripts/make-linux-tarball.sh amd64
run "Linux arm64" bash scripts/make-linux-tarball.sh arm64
run "Windows x64" bash scripts/make-windows-installer.sh

echo
find build/dist -maxdepth 1 -type f 2>/dev/null | sort | sed "s/^/  /"

if [ ${#FAILED[@]} -gt 0 ]; then
  echo "FAILED: ${FAILED[*]}" >&2
  exit 1
fi

echo "All platforms packaged."
