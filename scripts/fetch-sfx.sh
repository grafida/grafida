#!/usr/bin/env bash
#
# Grafida — Joomla content editing, untethered.
# Copyright (c) 2026 Nicholas K. Dionysopoulos
# GNU General Public License version 3, or later.
#
# Download the patched sibling-payload micro.sfx runtimes into build/sfx/.
#
# The binaries are built by GitHub Actions on the grafida/phpmicro fork
# (branch sibling-phar, workflow build-sfx.yml) and published as assets of the
# rolling "sfx-latest" release. With them in place, macOS builds become
# Developer-ID signable/notarisable and Windows builds become Authenticode
# signable — the compiled binary is split into a clean, signable stub plus a
# sibling <name>.phar payload (see build/readme/01-macos-signing.md and
# build/readme/04-exe-signing-on-macos.md).
#
# Usage:  scripts/fetch-sfx.sh [--force]
#   --force   re-download even when the file is already present
#
# Already-present files are kept (so a locally built SFX is never clobbered
# unless --force is given). A failed download leaves nothing behind; builds
# without build/sfx/ simply fall back to the stock (unsignable) Boson runtime.

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
BASE_URL="https://github.com/grafida/phpmicro/releases/download/sfx-latest"
ASSETS=(
  macos-aarch64.standard.sfx
  macos-x86_64.standard.sfx
  windows-x86_64.standard.sfx
  # Visual C++ 2015-2022 runtime DLLs (a zip, extracted below) — Boson's
  # libboson-windows-x86_64.dll imports them and a clean Windows lacks them, so
  # Grafida bundles them app-local next to grafida.exe (see make-windows-installer.sh).
  vc-runtime-x86_64.zip
)

FORCE=0
[ "${1:-}" = "--force" ] && FORCE=1

mkdir -p "$ROOT/build/sfx"
FAILED=0

for asset in "${ASSETS[@]}"; do
  target="$ROOT/build/sfx/$asset"
  if [ -f "$target" ] && [ "$FORCE" != 1 ]; then
    echo "✓ $asset already present (use --force to re-download)"
    continue
  fi

  echo "Downloading $asset …"
  tmp="$(mktemp)"
  if ! curl -fsSL -o "$tmp" "$BASE_URL/$asset"; then
    echo "  ✗ download failed — $BASE_URL/$asset" >&2
    rm -f "$tmp"
    FAILED=1
    continue
  fi

  # Verify against the published SHA-256 (best effort: skip if absent).
  if sum_expected="$(curl -fsSL "$BASE_URL/$asset.sha256" 2>/dev/null | awk '{print $1}')" && [ -n "$sum_expected" ]; then
    sum_actual="$(shasum -a 256 "$tmp" | awk '{print $1}')"
    if [ "$sum_actual" != "$sum_expected" ]; then
      echo "  ✗ checksum mismatch for $asset (expected $sum_expected, got $sum_actual)" >&2
      rm -f "$tmp"
      FAILED=1
      continue
    fi
  else
    echo "  (no .sha256 published — skipping checksum verification)"
  fi

  mv "$tmp" "$target"
  chmod 755 "$target"
  echo "  ✓ $target"
done

# Extract the VC++ runtime zip into build/sfx/vc-runtime/ so make-windows-installer.sh
# can bundle the loose DLLs app-local. Re-extract whenever the zip is present (cheap;
# keeps the folder in sync with a freshly downloaded zip).
VCRT_ZIP="$ROOT/build/sfx/vc-runtime-x86_64.zip"
if [ -f "$VCRT_ZIP" ]; then
  if command -v unzip >/dev/null 2>&1; then
    rm -rf "$ROOT/build/sfx/vc-runtime"
    mkdir -p "$ROOT/build/sfx/vc-runtime"
    if unzip -oq "$VCRT_ZIP" -d "$ROOT/build/sfx/vc-runtime"; then
      echo "  ✓ extracted VC++ runtime → build/sfx/vc-runtime/ ($(ls "$ROOT/build/sfx/vc-runtime" | tr '\n' ' '))"
    else
      echo "  ✗ could not extract $VCRT_ZIP" >&2
      FAILED=1
    fi
  else
    echo "  (unzip not found — leaving vc-runtime-x86_64.zip unextracted; the Windows" >&2
    echo "   installer will fall back to NOT bundling the VC++ runtime)" >&2
  fi
fi

exit $FAILED
