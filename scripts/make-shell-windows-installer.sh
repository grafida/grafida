#!/usr/bin/env bash
#
# Grafida — Joomla content editing, untethered.
# Copyright (c) 2026 Nicholas K. Dionysopoulos
# GNU General Public License version 3, or later.
#
# EXPERIMENTAL: the Windows (x64) installer for the native shell (gh-91, Phase 5 of gh-82), built by
# grafida/shell-packager from shell-package.json and build/shell-windows-installer.nsi. It is NOT
# part of `composer build`, `phing package` or `phing release`, which keep shipping Boson until the
# switch-over: this only runs when asked for (`composer build:shell-windows`,
# `phing package-shell-windows-x86`). It installs side by side with Boson's build.
#
# Usage:  scripts/make-shell-windows-installer.sh [shell-packager options, e.g. --no-check]
#
# Output: build/dist/Grafida-<version>-windows-amd64-shell-preview-Setup.exe (+ .sha256)
#
# Runs on the Mac (or Linux): makensis, Jsign and osslsigncode run there; the shell and the phpmicro
# SFX are downloaded from the releases pinned in shell-package.json and verified by SHA-256 (cached
# in ~/.cache/grafida-shell-packager, never build/sfx/). The packaged worker is run only on a
# Windows host, so here shell-packager says it skipped that check; the test guests run it.
#
# Signing: the same configuration as Boson's Windows build (scripts/sign-windows-exe.sh), from
# build/build.properties through the Phing target. With WINDOWS_SIGN_OP_ITEM set, the 1Password item
# gives the service principal, the Azure CLI logs in and fetches an access token, and shell-packager
# gets it as WINDOWS_SIGN_ENDPOINT / _PROFILE / _TOKEN / _TSAURL; it signs the shell, the worker stub,
# the uninstaller and the installer. Without it, everything is unsigned. As with Boson's build,
# Azure Artifact Signing's Basic tier is capped at 5,000 signatures a month: a signed build costs four.

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

[ -x vendor/bin/shell-packager ] || { echo "vendor/bin/shell-packager is missing: run composer install." >&2; exit 1; }

# The PHAR carries src/Support/App.php, so stamp App::VERSION from the CHANGELOG first, as every
# Boson compile does (idempotent; GRAFIDA_VERSION overrides).
php build/tasks/set-version.php
VERSION="$(php build/tasks/set-version.php --print)"

# The PHAR inside is the same for one commit (signatures and the NSIS archive are not reproducible).
export SOURCE_DATE_EPOCH="${SOURCE_DATE_EPOCH:-$(git log -1 --format=%ct)}"

OP_ITEM="${WINDOWS_SIGN_OP_ITEM:-}"
if [ -n "$OP_ITEM" ]; then
  for bin in az jsign op osslsigncode makensis; do
    command -v "$bin" >/dev/null 2>&1 || {
      echo "ERROR: '$bin' is required for a signed Windows build but was not found on PATH." >&2
      echo "       Install with: brew install azure-cli jsign 1password-cli osslsigncode makensis" >&2
      exit 1
    }
  done
  APP_ID="$(op item get "$OP_ITEM" --fields "label=${WINDOWS_SIGN_OP_FIELD_APPID:-appId}")"
  APP_SECRET="$(op item get "$OP_ITEM" --fields "label=${WINDOWS_SIGN_OP_FIELD_PASSWORD:-password}" --reveal)"
  TENANT="$(op item get "$OP_ITEM" --fields "label=${WINDOWS_SIGN_OP_FIELD_TENANT:-tenant}")"
  az login --service-principal -u "$APP_ID" -p "$APP_SECRET" --tenant "$TENANT" --output none
  unset APP_SECRET
  WINDOWS_SIGN_TOKEN="$(az account get-access-token --resource https://codesigning.azure.net --query accessToken -o tsv)"
  export WINDOWS_SIGN_TOKEN
  export WINDOWS_SIGN_ENDPOINT="${WINDOWS_SIGN_KEYSTORE:-weu.codesigning.azure.net}"
  export WINDOWS_SIGN_PROFILE="${WINDOWS_SIGN_ALIAS:-grafida-signing/GrafidaPublic}"
  export WINDOWS_SIGN_TSAURL="${WINDOWS_SIGN_TSAURL:-http://timestamp.acs.microsoft.com}"
  SIGNED="Trusted Signing ($WINDOWS_SIGN_PROFILE)"
else
  # Never sign with whatever happens to be in the environment: only the configured item signs.
  unset WINDOWS_SIGN_ENDPOINT WINDOWS_SIGN_PROFILE WINDOWS_SIGN_TOKEN WINDOWS_SIGN_PKCS12 WINDOWS_SIGN_PKCS12_PASSWORD
  SIGNED="no (windows.sign.op-item is empty)"
fi

echo "==> Grafida $VERSION, Windows x64, native shell (preview), signed: $SIGNED"
php vendor/bin/shell-packager windows-installer --config="$ROOT/shell-package.json" --app-version="$VERSION" "$@"
