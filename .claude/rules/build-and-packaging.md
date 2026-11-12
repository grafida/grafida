---
description: Grafida build, packaging, code signing (macOS notarisation + Windows Authenticode via the phpmicro sibling-phar SFX) and release process. Lifted from CLAUDE.md.
paths:
  - "build/**"
  - "scripts/**"
  - "build.xml"
  - "shell-package.json"
  - "composer.json"
  - "CHANGELOG"
  - "RELEASENOTES.md"
---

# Grafida build & packaging

Moved out of `CLAUDE.md` so it loads only when you are touching the build system. Deeper signing
documentation lives in `build/readme/01-macos-signing.md`, `02-signing-architecture.md`,
`03-sfx-maintenance.md` and `04-exe-signing-on-macos.md`.

## Build & packaging (one step)

Every release artefact is the native shell's (gh-92), built **on the Mac** by **grafida/shell-packager**
(a dev dependency pinned to an exact tag) over the repo-root **`shell-package.json`** and
**`shell.json`** (the app manifest, which names everything: `id`, `title`, `worker.binary`):

| Artefact | Phing target | Script |
|---|---|---|
| `Grafida-<v>-macos-arm64.dmg` | `package-macos-arm` | `scripts/make-macos-dmg.sh arm64` |
| `Grafida-<v>-macos-amd64.dmg` | `package-macos-x86` | `scripts/make-macos-dmg.sh amd64` |
| `Grafida-<v>-linux-amd64.tar.gz` | `package-linux-x86` | `scripts/make-linux-tarball.sh amd64` |
| `Grafida-<v>-linux-arm64.tar.gz` | `package-linux-arm` | `scripts/make-linux-tarball.sh arm64` |
| `Grafida-<v>-windows-amd64-Setup.exe` | `package-windows-x86` | `scripts/make-windows-installer.sh` |

each with a `.sha256` beside it, into **`build/dist/`** (gitignored). `phing package` (the default
target; `all` is an alias; `composer build:package`) builds all five with signing from
`build/build.properties`; `composer build` → `scripts/build-all.sh` runs the same scripts with
signing from the environment and a tolerant per-platform summary (one failure does not stop the
others, the exit status reports it). `composer build:macos|build:linux|build:windows` build one
platform. There is no compile step: the worker PHAR is built by shell-packager, the shell and the
phpmicro SFX are downloaded, pinned binaries. Boson's build (`boson.json`, `boson compile`, the
libboson patch, `build/sfx/`, the `.phar` and `.zip` artefacts) was deleted in gh-92.

**Front-end vendoring:** the build host needs `node`+`npm`. The npm-managed libraries (TinyMCE,
CodeMirror, FontAwesome — see `.claude/rules/spa-frontend.md`) are gitignored, so `prepare-assets`
(Phing) and `build-all.sh` run `composer run-script vendor:assets` before packaging to populate
`assets/private`, which the worker PHAR carries.

**Dev mode** is not a build: `composer shell:dev` (also `composer start`, `phing run`) builds the
sibling `../shell` clone with the automation channel and runs it with `--dev .`, so the worker is the
system `php bin/worker.php`.

## The Linux tarballs (gh-86, gh-92)

`composer build:linux [-- amd64|arm64]` / Phing `package-linux-x86|-arm` →
`scripts/make-linux-tarball.sh` → `vendor/bin/shell-packager linux-tarball` over
**`shell-package.json`** (repo root, beside `shell.json`, which names everything: `id`, `title`,
`worker.binary`). It stamps `App::VERSION` first (the PHAR carries `App.php`) and sets
`SOURCE_DATE_EPOCH` to the commit time, so **one commit builds one byte-identical tarball**. Output:
`build/dist/Grafida-<v>-linux-<arch>.tar.gz` + `.sha256`. Built on the Mac like everything else; the
worker check (below) only runs on a Linux host of the same architecture.
- **`linux.replaces: ["grafida"]`**: the tarball's `install.sh` removes Boson's
  `~/.local/share/applications/grafida.desktop` and that id's hicolor icons, but only when the entry
  exists and its main `Exec` ends in `/grafida` (Boson's installer wrote an unquoted absolute path);
  anything else under that id is left alone, and it says so. shell-packager's generated script, so
  app-agnostic: the list is the only Grafida part.

- **Pins:** `shell-package.json`'s `runtimes` is grouped by platform (`linux`, `macos` gh-88, `windows` gh-91) and
  pins the shell + launcher (a grafida/shell release tag) and the SFX (an **immutable**
  grafida/phpmicro `sfx-<YYYYMMDD>.<run>` release, never the rolling `sfx-latest`) by per-arch
  SHA-256; a mismatch is fatal. A runtime's `url` is one template (`{arch}`), or an object of one URL
  per architecture when they come from different releases (macOS, gh-92). ⚠️ **Never type a hash.**
  To bump one, download the release asset, compute its SHA-256 from the bytes, update the URL and
  the hashes, then prove every pin with `vendor/bin/shell-packager fetch --config=shell-package.json
  --arch=<x86_64|aarch64> [--platform=macos|windows]` (one hand-typed digit was caught that way in
  shell-packager's own fixture). The Linux pins are still shell 0.1.0 and `sfx-20261109.16`, the
  build #86's clean-VM runs verified; macOS arm64 pins shell 0.2.0 and `sfx-20261109.15`, macOS
  x86_64 shell 0.4.0 and `sfx-20261111.28` (gh-92); Windows pins shell 0.3.1 and `sfx-20261110.27`.
  Phase 7 (gh-82) brings them all to one release. Downloads are cached in
  `~/.cache/grafida-shell-packager`.
- **The Linux SFX links glibc dynamically** (2.35 floor, = the shell's), everything else static: musl
  has no NSS, so `.local`/systemd-resolved names would not resolve for sites or local AI servers.
- **The PHAR** is `bin/worker.php` + `src`, `assets/private|public`, `docs`, `language`,
  `storage/migrations`, `src/Ai/resources` and the `--no-dev` vendor `*.php`: 1,690 files against the
  Boson PHAR's 4,915. Its ini block sets `memory_limit=1G`, because a body at `shell.json`'s
  `maxBodyBytes` (128 MiB) peaks at ~600 MiB RSS (the body, the decoded JSON string, the decoded bytes,
  and `getimagesizefromstring()`'s copy); 512M died in `ImageInfo`. Keep the two in step:
  `tests/Pipe/PackagedWorkerTest::testABodyAtTheLimitFitsInMemory` pushes a body at the limit.
- **No dev dependency reaches the PHAR**: shell-packager takes `vendor/` from a `composer install
  --no-dev --no-scripts` staging copy, never the app's own `vendor/`, so PHPUnit, PHPStan and
  shell-packager itself never ship (the Boson PHAR carried all of them).
- **Testing a packaged build:** `GRAFIDA_PIPE_WORKER=<extracted>/grafida/grafida-worker composer
  test:pipe` runs the handshake, smoke and conformance tests against it, plus
  `PackagedWorkerTest` (Help from `phar://` docs, the `Resources::base()` extraction, a body at the
  limit). The shell-packager build itself runs the worker before writing (ini probe + `ready`) on a
  Linux host of the same arch.
- **The sweep (gh-92):** `grep -rni boson` over the tree (vendored code excluded) and over every
  artefact's PHAR listing must find only the `boson` scheme, or history.

## The macOS disk images (gh-88, gh-92)

`composer build:macos [-- arm64|amd64] [-- --no-style]` / Phing `package-macos-arm|-x86` →
`scripts/make-macos-dmg.sh <arch>` → `vendor/bin/shell-packager macos-app --arch=…` (into
`build/shell-macos/<arch>/Grafida.app`) then `macos-dmg --arch=… --app=…` →
`build/dist/Grafida-<v>-macos-<arm64|amd64>.dmg` + `.sha256`. It stamps `App::VERSION` and
`SOURCE_DATE_EPOCH` as the Linux build does, but only the PHAR inside is reproducible (`codesign` and
`hdiutil` are not).
- ⚠️ **Intel Macs (amd64) are tested only under Rosetta 2**, in Rosetta clones of the Apple Silicon
  guests (grafida/shell's `macvm.sh rosetta`); there is no Intel hardware or guest. On an Apple
  Silicon Mac with Rosetta 2, shell-packager runs the x86_64 worker check translated; without it,
  skips it with a note. Lifecycle: macOS 27 is the last for Intel Macs; deprecated when macOS 28
  drops Intel and Rosetta 2, dropped when macOS 29 lands (edit `UpdateJson::REQUIRES` then).

- **Signing comes from the environment**: `MACOS_SIGN_IDENTITY`
  (Developer ID, with a secure timestamp) and `MACOS_NOTARY_PROFILE` (notarytool submit, staple,
  validate, `spctl`). The Phing target fills them from `macos.sign.identity`/`macos.notary.profile`
  in `build/build.properties`; `composer build:macos` uses whatever the shell's environment
  has, so without them the build is ad hoc (but still under the hardened runtime). Verified:
  Gatekeeper reports "Notarized Developer ID" for the image and for the app inside it.
- **`Info.plist` is generated** by shell-packager from `shell.json` (`CFBundleIdentifier`
  `app.grafida`, the name), the CHANGELOG version and the shell's macOS 12.0 floor
  (`LSMinimumSystemVersion`, checked against every binary's `LC_BUILD_VERSION`). Grafida adds only
  `shell-package.json`'s `macos.infoPlist`: the copyright, the category and
  `NSAppTransportSecurity → NSAllowsArbitraryLoadsInWebContent` (see the AI assistant rules). A key
  shell-packager computes is an error there, which is why `NSHighResolutionCapable` is not listed.
  It is the only macOS floor: shell-packager refuses a binary whose `minos` is above it.
- **Entitlements**: the shell none, the worker `com.apple.security.cs.allow-jit` only
  (`build/macos/shell-worker.entitlements`, whitelisted in `build/.gitignore`), because PCRE's JIT
  needs MAP_JIT under the hardened runtime. shell-packager reads every signature back and fails
  unless it carries exactly the configured set. Add one only with the test that fails without it.
- **Testing a packaged build:** `GRAFIDA_PIPE_WORKER="$PWD/build/shell-macos/arm64/Grafida.app/Contents/MacOS/grafida-worker"
  composer test:pipe` (the amd64 one under Rosetta 2); `../shell/scripts/dev/parity-macos.sh` with
  `GRAFIDA_PACKAGED=…/Grafida.app SHELL_BIN=<the debug-automation-macos-<arch> artifact>` runs the
  in-page checks against a copy with only the shell swapped, and its first row proves which
  architecture runs. The pipe tests know macOS keeps the
  data in `~/Library/Application Support/Grafida`.
- **Checking a shell-packager bump.** Build the Linux tarballs (byte-identical for one commit, so
  compare hashes) and `phing package-macos-arm -Dmacos.sign.identity= -Dmacos.notary.profile=` (ad
  hoc, so signatures do not differ) before and after, and compare the worker PHAR **entry by entry
  by content** (never byte for byte: a PHAR carries per-build timestamped metadata), the stubs with
  `codesign --remove-signature`, and the bundle's other files. A dev-dependency bump then shows only
  the autoloader's lock-derived class suffix in the PHAR (gh-88's 0.1.0 → 0.2.0).

## The Windows installer (gh-91, gh-92)

`composer build:windows` / Phing `package-windows-x86` →
`scripts/make-windows-installer.sh` → `vendor/bin/shell-packager windows-installer` over
`shell-package.json` (its `windows` section) and **`build/shell-windows-installer.nsi`**
(whitelisted in `build/.gitignore`) → `build/dist/Grafida-<v>-windows-amd64-Setup.exe`
+ `.sha256`. **x64 only.** It stamps `App::VERSION` and `SOURCE_DATE_EPOCH` like the others; only the
PHAR inside is reproducible. ⚠️ makensis 3.13 aborts with `std::bad_alloc` when `LANG` is unset (an
agent's or CI shell can have none), so the script defaults it to `en_US.UTF-8`.

- **Built and signed on the Mac** (Linux works too; there is no Windows build host): makensis
  (3.08+, for `!uninstfinalize`), Jsign and osslsigncode run there, and shell-packager writes the
  icon and version resources into the shell and the worker stub **in PHP** (no `rcedit`), keeping
  their application manifests. The worker check (ini probe + `ready`) runs only on a Windows host,
  so a Mac build says it skipped it; the test guests run it and `tests/Pipe`.
- **Signing** is Azure Trusted Signing through the `windows.sign.*` properties: with `windows.sign.op-item` set, the script reads the service principal from
  1Password, logs `az` in, fetches an access token and hands shell-packager `WINDOWS_SIGN_ENDPOINT` /
  `_PROFILE` / `_TOKEN` / `_TSAURL`, which reach Jsign as `env:` references. **Four signatures per
  build** (the shell, the worker stub, the uninstaller through the `.nsi`'s
  `!uninstfinalize '${SHELL_PACKAGER_SIGN}'`, the installer), each checked with osslsigncode; mind
  the Basic tier's 5,000 a month. Without the item, nothing is signed (the script clears any
  `WINDOWS_SIGN_*` it inherits, so nothing signs by accident). The VC++ runtime Boson's build fetched
  is not needed: the shell links the CRT statically and the SFX imports system DLLs only, both
  checked on every release of theirs.
- **The installer script is Grafida's**; shell-packager passes the tree and its documented defines,
  plus `DISPLAYNAME`/`APPURL`/`COPYRIGHT` from `windows.installer.defines` (`PUBLISHER` is
  `windows.version.companyName`, Akeeba Ltd as the signer; the copyright is the author's). Per user;
  **it takes over Boson's identity** (gh-92): `%LOCALAPPDATA%\Programs\Grafida` (or
  `HKCU\Software\Grafida\InstallDir`, which it keeps writing; read by `InstallDirRegKey` before
  Boson's uninstaller deletes it), the uninstall key `Grafida`, the Start menu entry
  `Grafida\Grafida.lnk`. The executable keeps Boson's path (NTFS ignores case), so taskbar pins keep
  working. Before it writes a file it runs `ENSURE_NOT_RUNNING_IN` on this folder (which covers
  Boson's `grafida.exe`) and on the preview's, then `REMOVE_BOSON` (when `libboson-windows-x86_64.dll`
  or `grafida.phar` is there: Boson's `uninstall.exe /S _?=$INSTDIR`, waited for, then Boson's known
  files deleted by name whatever that did) and `REMOVE_PREVIEW` (gh-91's `Programs\app.grafida`, key
  `app.grafida`, shortcut "Grafida (shell preview)", the same way). Cancelled after that, neither
  build is left. Nothing ever touches `%APPDATA%\Grafida` (the user data every build shares) or the
  WebView2 profile (`%LOCALAPPDATA%\grafida`). No WebView2 bootstrapper: a missing runtime gets a
  warning with the download page, and the shell alerts at launch.
  ⚠️ **A running Grafida holds both executables open**: the installer could not write them (NSIS's
  Abort/Retry/Ignore) and the uninstaller skipped them silently while reporting success, its key
  already gone. `ENSURE_NOT_RUNNING_IN` opens them for writing before any file is touched and asks
  the user to close the app (Retry/Cancel); silent mode exits 2. It probes files, never process
  names: a `taskkill /IM Grafida.exe` would match any Grafida anywhere.
  **Leave its wording alone on a non-English Windows** (gh-92, decided): the installer is English
  only, Windows draws a MessageBox's buttons in the OS language, so a Greek system shows "Close it,
  then click Retry." above "Επανάληψη". That is the disparity users of a localised Windows expect
  from every English app; a message naming a button in another language would read as wrong. No
  rewording, and no NSIS languages for this alone.
- **The worker is phpmicro's GUI SFX** (`windows-x86_64.standard.sfx` from the immutable
  `sfx-20261110.27`, never `sfx-latest`): it opens no console, and the fork keeps
  `CREATE_NO_WINDOW` for its own children (static-php-cli's patch had counted the micro SAPI as CLI
  and dropped it), and resolves its sibling `.phar` through the file's final path (an 8.3 short
  name in the path had defeated `realpath`). The shell starts it suspended and assigns it to a
  Job Object (kill on close) before it runs; it uses shell-php 0.2.1, whose worker reads stdin as a
  blocking pipe instead of polling it.
- **Testing a packaged build** (in the test guests, scripted from the host: grafida/shell's
  `scripts/dev/winvm.sh` and `parity-windows.sh`): install it silently (`/S`), then
  `GRAFIDA_PIPE_WORKER="$env:LOCALAPPDATA\Programs\Grafida\grafida-worker.exe" composer test:pipe`,
  and `GRAFIDA_PACKAGED='…\Programs\Grafida' parity-windows.sh` for the in-page checks on a copy
  with only the shell swapped for the debug automation build.

## Phing

**Phing is expected as a globally-installed command** (`phing` on the PATH; it is deliberately *not*
a Composer dev dependency). Every `package-*` target depends on `prepare` (sub-targets
`prepare-composer` + `prepare-icons` + `prepare-assets` + `set-version`), which Phing runs once per
invocation. **`prepare-composer` runs `composer install --no-scripts` on every invocation**, so
vendor/ always matches `composer.lock` after a pull or on another machine (a stale
grafida/shell-packager once failed a build with "Unknown command"); it costs under a second when
there is nothing to do, and `--no-scripts` keeps the ~5 s npm post-install-cmd out of it. The icon
and asset steps are guarded: they re-rasterise the icons and vendor the front-end libraries only when
their output is missing (force a re-vendor with `-Drefresh.assets=1`), which is also how a fresh
`git clone … && phing` gets its front-end libraries. **`set-version`** (`build/tasks/set-version.php`):
the **`CHANGELOG` is the single source of truth for the version** — its topmost entry's heading ends
with the version number (the project convention, e.g. `Grafida 0.4`), and the step stamps it into
`App::VERSION` in `src/Support/App.php` before every package (idempotent; no-ops when already
current). `GRAFIDA_VERSION` overrides the CHANGELOG. So every package reports the CHANGELOG version in
the worker and the About dialog.

**`windows.sign.op-item` is the one signing switch** for `package-windows-x86`: the target branches
on it (`<if>`). Empty: the script runs with no signing settings and nothing is signed. Set: a signed
build, with the other `windows.sign.*` properties passed as they are; getting them right is the
maintainer's job, not the build's. (Passed positionally, a blank `op-item` used to vanish, since
Phing drops an empty `<arg>`, and the next property, `appId`, was taken for the item. Phing's `<env>`
is no way out: it pastes `export K=V;` into a shell line unquoted, which is also why the signing
values reach the scripts as positional args re-exported in a `bash -c` prologue.) The macOS targets
pass `macos.sign.identity` and `macos.notary.profile` the same way.

**Private build configuration:** `build.xml` loads `build/build.properties` (gitignored — holds
secrets, never committed; a missing file is tolerated). The committed `build/build.sample.properties`
is the template (`cp` it to `build/build.properties` and fill in): the signing properties above,
GitHub Releases (`github.organization`, `github.repository`, `github.token` — the PAT), the CDN upload
over FTPS (`cdn.ftp.hostname`, `cdn.ftp.username`, `cdn.ftp.password`, `cdn.ftp.directory`) and the
documentation upload (`docs.sftp.*`). (`build/.gitignore` ignores everything under `build/` except a
whitelist, so `build.properties` is ignored automatically; the sample is explicitly whitelisted.)

**Run:** the `run` target (also `composer start`) is `composer shell:dev`.

**Tests:** the `tests` target (depends only on `prepare-composer`, not the full `prepare`) runs
`composer test` — the PHPUnit suites (unit + integration + feature + pipe) **and `test:js`**. `phpunit.xml`
sets `failOnEmptyTestSuite="false"` because `tests/Integration/` was originally scaffolding only;
without that flag PHPUnit fails the whole run on an empty suite before the feature suite executes.
- **`composer test:js`** (`node --test 'tests/js/**/*.test.mjs'`) covers the SPA modules PHPUnit
  **cannot** reach: `assets/private/js/ai/providers.js` (the AI transport — the provider call runs in
  the SPA, see the AI facts), `assets/private/js/editor/slashtools.js` (the slash-command menu),
  `assets/private/js/editor/csstheme.js` (the editor colour-scheme rewriter, gh-38), and
  `assets/private/js/editor/localmedia.js` (the `boson://app/api/media/{id}/raw?rev=…` URL
  builder/parser, gh-36 — its own synchronous SHA-1 is what the rev token is verified against —
  **plus**, since gh-43, `fitDimensions()`, the JS half of the image-resync sizing rule whose PHP
  twin is `Grafida\Media\ImageDimensions::fit()`, see `.claude/rules/media-and-publish.md`).
  For all four it is the only automated coverage. It uses node's built-in test runner and loads the
  browser IIFE in a `vm` context with fakes for the globals app.js supplies (`window`/`fetch`/`api`,
  or `State`/`t`/`editor`); no bundler and no new dependency (node is already a build prerequisite).
  ⚠️ **The sandbox is its own realm**, which bites twice: providers.js detects a CORS failure with
  `err instanceof TypeError`, so a stub must mint that error **inside** the sandbox or the fallback
  never triggers; and a value *returned* from the sandbox (slashtools' `fetchItems()` array) fails a
  strict deep-equal against an outer-realm literal on the prototype alone, so re-home it first
  (`Array.from()`) or compare field by field.
- **`tests/Integration/Ai/ResponsesApiLiveTest.php`** talks to a **real** OpenAI *Responses API*
  server. It pins the wire-format assumptions providers.js is built on (the `output[]`→`output_text`
  shape, `instructions`, the typed SSE events with **no `[DONE]`**, and that a `previous_response_id`
  really does resume server-side and a stale one really is rejected) — if OpenAI changes the shape,
  the JS would break silently in the webview; this fails loudly instead. It is **skipped unless
  configured** via `GRAFIDA_TEST_RESPONSES_ENDPOINT` + `_MODEL` (+ `_KEY` for a hosted provider,
  `_PROVIDER` to override the providers.json key). A local LM Studio server works as the endpoint.
- **Test configuration lives in `tests/.env`** (gitignored — it holds provider credentials); copy
  `tests/.env.sample` to create it. `tests/bootstrap.php` (the PHPUnit `bootstrap`) loads it with
  **symfony/dotenv** (a dev dependency), and a variable exported in the real environment still wins,
  so `FOO=bar composer test` overrides the file. **`tests/README.md` documents all of this** — the
  suites, how to configure and run the live tests, and the two traps in them (a local server may
  *ignore* an unknown `previous_response_id` rather than rejecting it, and these tests go through PHP
  so they do not exercise the CORS/ATS constraints the SPA hits).

**Release:** `all` is an alias for `package`. `release` (depends on `all`) is the standard release
process, and stays **one step** (gh-92): package every platform, then (1) create a **published
GitHub release** with the five artefacts and their `.sha256` files as assets and `RELEASENOTES.md` as
the description, (2) build `grafida.json` from that release, and (3) upload it to the CDN over
**FTPS**. It needs the `github.*` + `cdn.ftp.*`
properties from `build/build.properties` (it fails early with a clear message if they're unset). Three
custom Phing tasks under `build/tasks/` (namespace `tasks\`, taskdef'd with `classpath="…/build"`)
back it, all curl-based (no extra binaries, matching `UpdateJson.php`): **`GitHubRelease`** (creates a
draft release, uploads each nested-`<fileset>` asset, then publishes — so a partial release is never
visible), **`UpdateJson`** (fetches the latest release's metadata into `grafida.json`; its `version` is
the bare tag via `versionOf()`, never the release name "Grafida 0.4", which every Grafida's
`version_compare()` ranks below any number: that is why 0.3 was offered to nobody, gh-92), and
**`FtpsUpload`** (uploads over explicit FTPS — `CURLUSESSL_ALL`). The version comes from the CHANGELOG
via `set-version.php --print`. `UpdateJson` treats a release as downloadable when it has any asset
ending in `.zip`/`.exe`/`.dmg`/`.tar.gz` (`UpdateJson::ASSET_EXTENSIONS` / `isDownloadableAsset()`); its
`grafida.json` `download` field is provisionally the **first** such asset — a real per-platform download
map is for when the update mechanism itself is built; the Download button opens the release page
(`infoURL`) anyway. ⚠️ **`UpdateJson` also writes `requires`** (gh-92) from its `REQUIRES` table: per OS,
the architectures and the minimum OS version this release runs on (macOS arm64 + x86_64 from 12.0,
Windows x86_64 from `10.0.19045`, Linux x86_64 + arm64). 0.4's `UpdateService` treats a release that
excludes this machine as "no update" (`Update\HostPlatform` reads the OS, this build's architecture and
the OS version; whatever it cannot detect never hides an update); clients up to 0.3 ignore the
member. It is how a platform losing support (Intel Macs, from macOS 29) stops being notified: remove
it from the table for the first release that drops it.

## Release procedure

The exact procedure that worked for 0.1; it refines the generic release-workflow guidance.
The version comes from the CHANGELOG's top entry; `-Dversion=X.Y.Z` overrides it and stamps
`App::VERSION`.

1. **Release notes.** `RELEASENOTES.md` (GitHub-flavoured Markdown) must target the upcoming
   version, reviewed against the CHANGELOG, and end with that version's CHANGELOG pasted in (see
   "Release-notes layout" below). Paste it again whenever the CHANGELOG changes before the release.
2. **Check credentials.** `build/build.properties` must have non-blank `github.*` and `cdn.ftp.*`
   values, or the release fails early.
3. **Full packaging build.** Clear stale `build/dist/*` first, then `phing package -Dversion=X.Y.Z`.
   This produces the real DMGs, tarballs and installer (macOS DMGs notarised, Windows
   Authenticode-signed).
4. ⛔ **HUMAN-VERIFICATION BREAKPOINT — the key step.** Stop after step 3 and wait for the human to
   install and test the **fully packaged artifacts in `build/dist/`** (the DMG/installer). Do not tag
   or publish until they confirm. The breakpoint belongs here, after full packaging: anything
   earlier tests nothing that a user will actually run.
5. **Commit** — only if the working tree is dirty. `set-version` is idempotent, so a CHANGELOG that
   already names the version leaves nothing to commit and this step is skipped.
6. **Tag:** `git tag X.Y.Z -sm "Tagging X.Y.Z"` (signed), then `git push origin X.Y.Z`.
7. **Publish:** `phing release -Dversion=X.Y.Z` — repackages all platforms, creates and publishes a
   GitHub release with the five artefacts and their five `.sha256` files, generates `grafida.json`
   (with `requires`) and uploads it over FTPS to the BunnyCDN `updates/` directory.
8. **Verify:** `curl https://cdn.akeeba.com/updates/grafida.json` reports the new version and its
   `requires`, and `gh release view X.Y.Z --repo grafida/grafida` shows `draft=false` with all ten
   assets.
9. **Post-release Windows checks** (gh-92), on the published assets, in the Windows guests: the
   installer downloaded over HTTPS from the GitHub release with Edge, SmartScreen's verdict on it, and
   Smart App Control in enforcing mode if the Windows 11 guest's evaluation state can still be
   switched on. They need the real GitHub asset (its Mark of the Web, its download reputation), so
   they cannot run on a release candidate.

⚠️ **Publishing the documentation to Grafida.app is not part of this and must not be added to it.**
`scripts/upload-docs.sh` (`composer docs:publish`) uploads `docs/` over SFTP whenever the pages
change; the app serves Help out of its own binary, so the site and the shipped build are
independent, and folding the upload into `release` would leave the public documentation stale
between versions. That is also why it is not a Phing target. See
`build/readme/05-documentation-publishing.md` and `.claude/rules/documentation.md`.

The whole flow builds no feature branch — commit on `main`.

## Release-notes layout

`RELEASENOTES.md` is the GitHub release's description, sent as it is (`GitHubRelease`'s `bodyFile`):
nothing is added to it at release time. It is the hand-written sections (Highlights, Notable fixes,
Coming from an earlier version), then **`### CHANGELOG` at the very bottom**: the upcoming version's
CHANGELOG entries pasted in as a list, each prefix turned into its emoji (`!` ‼️, `+` ✨, `-` ✂️,
`~` ✏️, `#` 🐞 keeping its `[HIGH]`/`[MEDIUM]`/`[LOW]`; 🚨 for a security update), followed by the
`Legend:` list of the six, as Panopticon's release notes do.

- **No downloads list and no download links.** GitHub lists the release's assets, the `.sha256`
  files included, below the description; a table of them only repeats that and goes stale.
- **No link to the `CHANGELOG` file.** The changelog is the section at the bottom.

## CHANGELOG style

Entries are **terse** — one short line each. No prose sentences, no "because…"/"so that…"
explanations, no restating what the feature does in three clauses. The CHANGELOG is user-facing
release material (it also drives RELEASENOTES and is the version source of truth), and readers
scan it.

Within each version block, entries are **grouped and ordered by significance**:

1. `!` — important change (may also be a security update; never means "breaking")
2. `+` — new features
3. `-` — removals
4. `~` — changes to existing behaviour
5. `# [HIGH]` — high-severity bug fixes
6. `# [MEDIUM]`
7. `# [LOW]`

When adding an entry, write the shortest line that identifies the change, then **place it in its
group** — do not simply append to the end of the block.
