---
description: Grafida build, packaging, code signing (macOS notarisation + Windows Authenticode via the phpmicro sibling-phar SFX) and release process. Lifted verbatim from CLAUDE.md.
paths:
  - "build/**"
  - "scripts/**"
  - "build.xml"
  - "boson.json"
  - "composer.json"
  - "CHANGELOG"
  - "RELEASENOTES.md"
---

# Grafida build & packaging

Moved verbatim out of `CLAUDE.md` so it loads only when you are touching the build system.
Deeper signing documentation lives in `build/readme/01-macos-signing.md`,
`02-signing-architecture.md`, `03-sfx-maintenance.md` and `04-exe-signing-on-macos.md`.

## Build & packaging (one step)

**Front-end vendoring:** the build host needs `node`+`npm`. The npm-managed libraries (TinyMCE,
CodeMirror, FontAwesome — see `.claude/rules/spa-frontend.md`) are gitignored, so `build-all.sh`
runs `composer run-script vendor:assets` before compiling to populate `assets/private` (which
`boson.json` bundles at compile time).

`composer build` → `scripts/build-all.sh` is the **one-shot** compile-and-package pipeline; it
runs `boson compile` (every target in `boson.json`) then packages each platform into
**`build/dist/`** (gitignored) by delegating to the per-platform `scripts/make-*.sh` helpers (the
same ones the Phing `package-*` targets call — single source of truth). The version comes from
`App::VERSION` (override via `GRAFIDA_VERSION`). Per-platform packaging is tolerant (missing binary →
warn+skip), but a failing compile or a genuine packaging-tool error is fatal. Pieces:
- macOS (arm64+amd64, macOS host only): `scripts/make-macos-app.sh <arch>` assembles
  `build/macos/<bosondir>/Grafida.app` — Boson names the arm64 dir `aarch64`, amd64 stays
  `amd64` — then `scripts/make-dmg.sh <arch>` wraps it (via `hdiutil`) into
  `Grafida-<v>-macos-<arch>.dmg` with an `/Applications` symlink. **The DMG has a branded
  Finder layout**: it mounts the writable UDRW image, drops the background artwork into
  `.background/background.tiff`, then `osascript`s Finder to hide the toolbar, set a 640×400
  window with a 128px icon view, apply the background, and position the app at `{160,210}`
  next to `/Applications` at `{480,210}` (the background draws the header + a "drag onto
  Applications" arrow in the gap between them). It also writes a `.VolumeIcon.icns` — **after**
  the Finder styling, since opening the volume in Finder deletes a pre-existing one. The whole
  styling stage is **best-effort**: a missing asset or an `osascript` failure (e.g. the macOS
  automation-permission-to-control-Finder TCC prompt is denied) only warns and still emits a
  functional plain DMG. The background is a committed raster (`assets/logo/dmg-background.{png,
  @2x.png,tiff}`) rendered from the single SVG master `assets/logo/dmg-background.svg` by
  `scripts/make-dmg-background.sh` — a multi-resolution `.tiff` (`tiffutil -cathidpicheck`) for
  retina — following the same "SVG master → committed raster" pattern as the app icons;
  `scripts/make-icons.sh` invokes it too, so one command refreshes all visual assets. **Code signing works via a
  patched SFX runtime** — docs: `build/readme/01-macos-signing.md` (Apple setup + recipe +
  unsignability analysis), `02-signing-architecture.md` (architecture, why the fork exists,
  design decisions), `03-sfx-maintenance.md` (PHP/Boson bump playbook, troubleshooting): a
  `micro.sfx` built with static-php-cli from the `grafida/phpmicro` fork's `sibling-phar`
  branch (which adds an additive fallback — no appended payload → load `"<self>.phar"`, then
  `"../Resources/<self>.phar"`, realpath-canonicalised so the offset stream hooks keep
  matching) lives in the gitignored `build/sfx/<os>-<cpu>.standard.sfx`. **The fork's own
  GitHub Actions (`build-sfx.yml`) builds the SFX for macOS arm64+x86_64 AND Windows x86_64 on
  every push to `sibling-phar`** (via stock static-php-cli pointed at the repo with `-L`; Windows
  is a separate `build-windows` job — MSVC/php-sdk toolchain, PowerShell smoke test) and publishes
  them to the rolling `sfx-latest` release; `scripts/fetch-sfx.sh` downloads + SHA-256-verifies
  the assets (`macos-{aarch64,x86_64}` + `windows-x86_64`) into `build/sfx/` (never overwrites
  existing files; `--force` re-downloads) and runs automatically from the Phing `prepare-sfx` step
  (macOS git targets) and `build-all.sh` — best-effort, offline builds fall back to the stock runtime.
  `build/tasks/compile-target.php` injects the SFX as the Boson target's `sfx` when present,
  and pre-cleans the output dirs — Boson's cleanup chokes on the previous `Grafida.app`,
  silently leaving a stale binary; `build-all.sh` compiles through `compile-target.php --all`
  for the same reason. `make-macos-app.sh` detects the patched runtime, splits the
  compiled binary into a clean Mach-O stub (`Contents/MacOS/grafida`) + payload
  (`Contents/Resources/grafida.phar`; codesign refuses data files in `Contents/MacOS`, so
  `assets/` also live in Resources with a dylib symlink for the phar's mounts), and signs the
  whole bundle — ad-hoc by default, Developer ID + hardened runtime + notarisation when
  `MACOS_SIGN_IDENTITY`/`MACOS_NOTARY_PROFILE` are set (verified end-to-end: notarisation
  Accepted, `spctl` "Notarized Developer ID"). Without `build/sfx/` the legacy combined-binary
  layout is used and Developer-ID signing aborts with a clear error.
- Linux (amd64+arm64): `scripts/make-linux-tarball.sh <arch>` builds a `.tar.gz` of the per-arch
  output dir (binary + `libboson-linux-*.so` + `assets/`) plus the icon, `grafida.desktop`, and
  `build/linux-install.sh` (renamed `install.sh`) — a per-user XDG desktop-integration installer.
  ⚠️ **The shipped `libboson-linux-*.so` is binary-patched (gh-79).** The prebuilt library reads a
  `boson://` request body with `g_input_stream_read_bytes(stream, G_MAXSSIZE, …)`, which `g_malloc`s
  9223372036854775807 bytes up front, so on WebKitGTK *every POST with a body* aborts the process
  (saucer/saucer#57, fixed upstream but never rebuilt into boson-php/saucer; Boson is unmaintained).
  `compile-target.php` runs `build/tasks/patch-libboson.php` on each Linux output dir's copy after a
  successful compile (never on `vendor/`), rewriting the one immediate to 64 MiB (x86_64 `movabs`,
  aarch64 `movz`). It stays a single `read()`, which is enough only because every internal API POST
  is one `JSON.stringify()`d string — **do not start POSTing a `Blob`/`FormData` to `boson://`**
  without revisiting it. Each library is pinned by SHA-256 before and after, so a changed library
  **fails the build**: disassemble `saucer::scheme::request::content()` — a chunked loop means the
  bug is fixed and the entry can go, otherwise re-derive offset + hashes.
- Windows (amd64): `scripts/make-windows-installer.sh` compiles `build/windows-installer.nsi` with
  **NSIS** `makensis`, which runs natively on macOS/Linux (no Wine/Docker/Windows) →
  `Grafida-<v>-windows-amd64-Setup.exe` (per-user install in `%LOCALAPPDATA%\Programs\Grafida`).
  Falls back to a portable `.zip` if `makensis` is absent. **Authenticode signing works the same
  way as macOS — by splitting.** `boson compile` appends the PHAR after the PE stub; signing the
  combined binary appends the certificate *past* the PHAR and corrupts its trailing signature, so
  the app dies at startup on `Phar::mapPhar` ("grafida.exe has a broken signature"). So when the
  patched SFX (`build/sfx/windows-x86_64.standard.sfx`) is present, `make-windows-installer.sh`
  splits `grafida.exe` into a clean PE stub + sibling `grafida.phar` (offset from
  `build/tasks/pe-sfxsize.php`, which replicates phpmicro's `max(PointerToRawData+SizeOfRawData)`
  and asserts Boson's extra-ini magic `fd f6 69 e6` sits there), signs **only the stub** (Jsign/Azure
  Trusted Signing via `scripts/sign-windows-exe.sh`, gated on `WINDOWS_SIGN_OP_ITEM`), and NSIS
  ships both (`HAVE_PHAR`). `sign-windows-exe.sh` **refuses** to sign any PE still carrying a PHAR
  overlay (runs `pe-sfxsize.php` as a tripwire), so the combined binary can never be signed by
  accident; the installer `Setup.exe` (NSIS overlay, not a PHAR) signs fine. Without the patched
  SFX the unsigned combined binary ships (it works); if signing is configured but the SFX is
  missing, the script aborts rather than emit a broken signed binary. Docs:
  `build/readme/04-exe-signing-on-macos.md`, `02-signing-architecture.md`.
  **The installer bundles the Visual C++ 2015-2022 runtime app-local.** Boson's
  `libboson-windows-x86_64.dll` imports `MSVCP140*`/`VCRUNTIME140*`, which a clean Windows
  (especially Server) lacks — without them grafida.exe dies at startup with an FFI *"The
  specified module could not be found"*. Windows resolves a DLL's imports from the app dir first,
  so the four CRT DLLs ride next to grafida.exe (no admin, unlike the machine-wide redist). They
  are collected by the phpmicro `build-windows` CI (the only Windows box with VS) and published as
  `vc-runtime-x86_64.zip`; `scripts/fetch-sfx.sh` downloads + extracts them to `build/sfx/vc-runtime/`,
  `make-windows-installer.sh` copies them into the package dir, and NSIS ships every `*.dll` beside
  the exe (`File "${SRCDIR}/*.dll"` — libboson + the VC runtime). Best-effort: a build without the
  fetched runtime just omits them.
  **The flashing CMD window is suppressed at startup.** grafida.exe runs on a console-subsystem
  PHP runtime (the phpmicro SFX is a CLI build), so Windows gives it a console. `index.php` hides
  it immediately via FFI (`ShowWindow(GetConsoleWindow(), SW_HIDE)`), which also stops the
  per-click flashing: the console subprocesses the backend spawns (`Grafida\Secret\ProcessRunner`
  — the registry theme probe) **inherit** the hidden console instead of each popping a fresh visible
  one. **The secret store no longer spawns at all:** the old `WindowsSecretStore` shelled out to a
  whole `powershell.exe` (~1s cold start) for every DPAPI protect/unprotect, and because the
  `boson://` kernel is single-threaded that froze the UI on every request needing a stored secret
  (site token, AI key) — the multi-second stall. `Grafida\Secret\WindowsDpapi` now calls
  `crypt32.dll`'s `CryptProtectData`/`CryptUnprotectData` **directly via FFI** (sub-millisecond, no
  subprocess); it is byte-compatible with the .NET `ProtectedData` CurrentUser/no-entropy blob the
  PowerShell path wrote, so existing secrets keep working, and PowerShell remains a fallback only
  when FFI is unavailable. `WindowsSecretStore` also memoises decrypted secrets for the session (it
  is a container singleton) and no longer probes with `where powershell`. The registry theme probe
  (`DisplayModeService::windowsPrefersDark()`, on window focus) also **no longer spawns**: it reads
  the `AppsUseLightTheme` DWORD directly via FFI (`Grafida\Display\WindowsThemeReader`, calling
  `advapi32.dll`'s `RegGetValueA`), because `proc_open` does not pass `CREATE_NO_WINDOW` and a
  `reg.exe` child could still briefly flash a console even with the hidden console; `reg.exe` remains
  a fallback only when FFI is unavailable.
- PHAR: `scripts/make-phar-dist.sh` copies the compiler's `build/phar/grafida.phar` to `Grafida-<v>.phar`.

## Native-shell Linux tarball (experimental, gh-86)

`composer build:shell-linux [-- amd64|arm64]` / Phing `package-shell-linux-x86|-arm` →
`scripts/make-shell-linux-tarball.sh` → `vendor/bin/shell-packager linux-tarball` over
**`shell-package.json`** (repo root, beside `shell.json`, which names everything: `id`, `title`,
`worker.binary`). It stamps `App::VERSION` first (the PHAR carries `App.php`) and sets
`SOURCE_DATE_EPOCH` to the commit time, so **one commit builds one byte-identical tarball**. Output:
`build/dist/Grafida-<v>-linux-<arch>-shell-preview.tar.gz` + `.sha256`. ⚠️ **It must never become a
dependency of `package`/`all`/`release`**: release builds stay on Boson until the switch-over (gh-82).

- **Pins:** `shell-package.json`'s `runtimes` is grouped by platform (`linux`, `macos` gh-88, `windows` gh-91) and
  pins the shell + launcher (a grafida/shell release tag) and the SFX (an **immutable**
  grafida/phpmicro `sfx-<YYYYMMDD>.<run>` release, never the rolling `sfx-latest`) by per-arch
  SHA-256; a mismatch is fatal. ⚠️ **Never type a hash.** To bump one, download the release asset,
  compute its SHA-256 from the bytes, update the URL and the hashes, then prove every pin with
  `vendor/bin/shell-packager fetch --config=shell-package.json --arch=<x86_64|aarch64>
  [--platform=macos|windows]` (one hand-typed digit was caught that way in shell-packager's own
  fixture). The Linux pins are still shell 0.1.0, the build #86's clean-VM runs verified; macOS pins
  shell 0.2.0 and `sfx-20261109.15`; Windows pins shell 0.3.1 and `sfx-20261110.27`. Downloads are cached in
  `~/.cache/grafida-shell-packager`, **never `build/sfx/`**: `compile-target.php` injects any
  `build/sfx/linux-*.standard.sfx` into the Boson Linux build.
- **The Linux SFX links glibc dynamically** (2.35 floor, = the shell's), everything else static: musl
  has no NSS, so `.local`/systemd-resolved names would not resolve for sites or local AI servers.
- **The PHAR** is `bin/worker.php` + `src`, `assets/private|public`, `docs`, `language`,
  `storage/migrations`, `src/Ai/resources` and the `--no-dev` vendor `*.php`: 1,690 files against the
  Boson PHAR's 4,915. Its ini block sets `memory_limit=1G`, because a body at `shell.json`'s
  `maxBodyBytes` (128 MiB) peaks at ~600 MiB RSS (the body, the decoded JSON string, the decoded bytes,
  and `getimagesizefromstring()`'s copy); 512M died in `ImageInfo`. Keep the two in step:
  `tests/Pipe/PackagedWorkerTest::testABodyAtTheLimitFitsInMemory` pushes a body at the limit.
- **shell-packager stays out of the Boson PHAR** through `boson.json`'s `not-directory`. ⚠️ Box
  resolves those **relative to the finder's `in` directory (`vendor/`)**, so the working entries are
  `grafida/shell-packager` and `seld/phar-utils`; the old `vendor/boson-php/compiler` entry matches
  nothing, and the Boson PHAR has always carried the dev tools (PHPUnit, php-cs-fixer, PHPStan, the
  compiler). That is left alone until the switch-over, since changing it changes what ships. A new
  dev dependency must be excluded the same way, verified by listing the packed PHAR before and after.
- **Testing a packaged build:** `GRAFIDA_PIPE_WORKER=<extracted>/grafida/grafida-worker composer
  test:pipe` runs the handshake, smoke and conformance tests against it, plus
  `PackagedWorkerTest` (Help from `phar://` docs, the `Resources::base()` extraction, a body at the
  limit). The shell-packager build itself runs the worker before writing (ini probe + `ready`) on a
  Linux host of the same arch.

## Native-shell macOS disk image (experimental, gh-88)

`composer build:shell-macos [-- --no-style]` / Phing `package-shell-macos-arm` →
`scripts/make-shell-macos-dmg.sh` → `vendor/bin/shell-packager macos-app` (into
`build/shell-macos/Grafida.app`) then `macos-dmg --app=…` → `build/dist/Grafida-<v>-macos-arm64-shell-preview.dmg`
+ `.sha256`. **Apple Silicon only**: there is no Intel shell, SFX pin or image. It stamps `App::VERSION`
and `SOURCE_DATE_EPOCH` as the Linux build does, but only the PHAR inside is reproducible (`codesign`
and `hdiutil` are not). ⚠️ Like the Linux tarball, never a dependency of `package`/`all`/`release`.

- **Signing comes from the environment**, the variables Boson's scripts read: `MACOS_SIGN_IDENTITY`
  (Developer ID, with a secure timestamp) and `MACOS_NOTARY_PROFILE` (notarytool submit, staple,
  validate, `spctl`). The Phing target fills them from `macos.sign.identity`/`macos.notary.profile`
  in `build/build.properties`; `composer build:shell-macos` uses whatever the shell's environment
  has, so without them the build is ad hoc (but still under the hardened runtime). Verified:
  Gatekeeper reports "Notarized Developer ID" for the image and for the app inside it.
- **`Info.plist` is generated** by shell-packager from `shell.json` (`CFBundleIdentifier`
  `app.grafida`, the name), the CHANGELOG version and the shell's macOS 12.0 floor
  (`LSMinimumSystemVersion`, checked against every binary's `LC_BUILD_VERSION`). Grafida adds only
  `shell-package.json`'s `macos.infoPlist`: the copyright, the category and
  `NSAppTransportSecurity → NSAllowsArbitraryLoadsInWebContent` (see the AI assistant rules). A key
  shell-packager computes is an error there, which is why `NSHighResolutionCapable` is not listed.
  This replaces the `App::MIN_MACOS` coupling below **for the shell build only**.
- **Entitlements**: the shell none, the worker `com.apple.security.cs.allow-jit` only
  (`build/macos/shell-worker.entitlements`, whitelisted in `build/.gitignore`), because PCRE's JIT
  needs MAP_JIT under the hardened runtime. shell-packager reads every signature back and fails
  unless it carries exactly the configured set. Add one only with the test that fails without it.
- **Testing a packaged build:** `GRAFIDA_PIPE_WORKER="$PWD/build/shell-macos/Grafida.app/Contents/MacOS/grafida-worker"
  composer test:pipe` (111 tests, none skipped); `../shell/scripts/dev/parity-macos.sh` with
  `GRAFIDA_PACKAGED=build/shell-macos/Grafida.app SHELL_BIN=<debug automation shell>` runs the
  in-page checks against a copy with only the shell swapped. The pipe tests know macOS keeps the
  data in `~/Library/Application Support/Grafida`.
- **Checking a shell-packager bump.** Build the Linux tarballs and `phing package-macos-arm
  -Dmacos.sign.identity= -Dmacos.notary.profile=` (ad hoc, so signatures do not differ) before and
  after, and compare. Boson's own output is not byte-reproducible: two unchanged builds give the
  app's payload different bytes (entry metadata) and a different DMG. What does reproduce, and what
  to compare: `build/phar/grafida.phar` entry by entry by content (never byte for byte: a PHAR
  carries per-build timestamped metadata, so two unchanged builds always differ in bytes), the
  payload entry by entry by content, the
  stub with `codesign --remove-signature`, and the bundle's other files. A dev-dependency bump then
  shows only Composer's metadata naming it (`installed.php`, the class maps) in Boson's payload,
  and only the autoloader's lock-derived class suffix in the shell PHAR (gh-88's 0.1.0 → 0.2.0).

## Native-shell Windows installer (experimental, gh-91)

`composer build:shell-windows` / Phing `package-shell-windows-x86` →
`scripts/make-shell-windows-installer.sh` → `vendor/bin/shell-packager windows-installer` over
`shell-package.json` (its `windows` section) and **`build/shell-windows-installer.nsi`**
(whitelisted in `build/.gitignore`) → `build/dist/Grafida-<v>-windows-amd64-shell-preview-Setup.exe`
+ `.sha256`. **x64 only.** It stamps `App::VERSION` and `SOURCE_DATE_EPOCH` like the others; only the
PHAR inside is reproducible. ⚠️ Never a dependency of `package`/`all`/`release`.

- **Built and signed on the Mac** (Linux works too; there is no Windows build host): makensis
  (3.08+, for `!uninstfinalize`), Jsign and osslsigncode run there, and shell-packager writes the
  icon and version resources into the shell and the worker stub **in PHP** (no `rcedit`), keeping
  their application manifests. The worker check (ini probe + `ready`) runs only on a Windows host,
  so a Mac build says it skipped it; the test guests run it and `tests/Pipe`.
- **Signing** is Azure Trusted Signing through the same `windows.sign.*` properties as Boson's
  Windows build: with `windows.sign.op-item` set, the script reads the service principal from
  1Password, logs `az` in, fetches an access token and hands shell-packager `WINDOWS_SIGN_ENDPOINT` /
  `_PROFILE` / `_TOKEN` / `_TSAURL`, which reach Jsign as `env:` references. **Four signatures per
  build** (the shell, the worker stub, the uninstaller through the `.nsi`'s
  `!uninstfinalize '${SHELL_PACKAGER_SIGN}'`, the installer), each checked with osslsigncode; mind
  the Basic tier's 5,000 a month. Without the item, nothing is signed (the script clears any
  `WINDOWS_SIGN_*` it inherits, so nothing signs by accident).
- **The installer script is Grafida's**; shell-packager passes the tree and its documented defines,
  plus `DISPLAYNAME`/`APPURL`/`COPYRIGHT` from `windows.installer.defines` (`PUBLISHER` is
  `windows.version.companyName`, Akeeba Ltd as the signer; the copyright is the author's). Per user; **side by side with Boson's
  build**: `%LOCALAPPDATA%\Programs\app.grafida` and the uninstall key `app.grafida`, against
  Boson's `Programs\Grafida` and `Grafida`, its own Start menu entry ("Grafida (shell preview)");
  the uninstaller removes exactly what it installed, never `%APPDATA%\Grafida` (the shared user
  data) or the WebView2 profile (`%LOCALAPPDATA%\grafida`). No WebView2 bootstrapper: a missing
  runtime gets a warning with the download page, and the shell alerts at launch.
  ⚠️ **A running Grafida holds both executables open**: the installer could not write them (NSIS's
  Abort/Retry/Ignore) and the uninstaller skipped them silently while reporting success, its key
  already gone. `ENSURE_NOT_RUNNING` opens them for writing before any file is touched and asks the
  user to close the app (Retry/Cancel); silent mode exits 2. Probe this install's files only: a
  `taskkill /IM Grafida.exe` would also match Boson's `grafida.exe`.
- **The worker is phpmicro's GUI SFX** (`windows-x86_64.standard.sfx` from the immutable
  `sfx-20261110.27`, never `sfx-latest`): it opens no console, and the fork keeps
  `CREATE_NO_WINDOW` for its own children (static-php-cli's patch had counted the micro SAPI as CLI
  and dropped it), and resolves its sibling `.phar` through the file's final path (an 8.3 short
  name in the path had defeated `realpath`). The shell starts it suspended and assigns it to a
  Job Object (kill on close) before it runs; it uses shell-php 0.2.1, whose worker reads stdin as a
  blocking pipe instead of polling it.
- **Testing a packaged build** (in the test guests, scripted from the host: grafida/shell's
  `scripts/dev/winvm.sh` and `parity-windows.sh`): install it silently (`/S`), then
  `GRAFIDA_PIPE_WORKER="$env:LOCALAPPDATA\Programs\app.grafida\grafida-worker.exe" composer test:pipe`
  (111 tests), and `GRAFIDA_PACKAGED='…\Programs\app.grafida' parity-windows.sh` for the in-page
  checks on a copy with only the shell swapped for the debug automation build.
- **Checking a shell-packager bump:** pack Boson's PHAR before and after
  (`php -d phar.readonly=0 build/tasks/compile-target.php --pack-only`, which writes
  `build/.temp/grafida.phar`, not `build/phar/`; the first run downloads Box) and compare entry by
  entry by content. The 0.2.0 → 0.3.0 bump
  changed only `vendor/composer/installed.php` (shell-packager's version, the root commit) and the
  two class maps (the new `ShellPackager\Windows` classes), of 4,915 entries.

**⚠️ The minimum macOS version is set by the vendored library, not by us.** `boson-php/saucer`
ships a prebuilt `libboson-darwin-universal.dylib` which `boson compile` copies verbatim into
every macOS target (`vendor/boson-php/compiler/src/Target/BuiltinTarget.php` — there is no `sfx`-style
config key to override it), and upstream builds it on `macos-latest` with **no
`CMAKE_OSX_DEPLOYMENT_TARGET`**, so its floor is whatever SDK the runner had: currently `minos 15.0`,
importing a libc++ symbol that does not exist earlier. `vtool -set-build-version` cannot lower it —
the symbol dependency is real (gh-58). Our own artefacts are fine (`minos 12.0`).
So **`App::MIN_MACOS` is the single source of truth**, with three consumers: `make-macos-app.sh`
seds it into `Info.plist`'s `LSMinimumSystemVersion` (a failed read is **fatal** — an *empty* value
means "no minimum" to LaunchServices, which is how gh-58 got as far as launching and then crashing),
`Grafida\Startup\StartupCheck` enforces it at run time for the launches LaunchServices does not gate
(the PHAR, and the binary run from a terminal), and `tests/Unit/Startup/MacosDeploymentFloorTest.php`
fails the suite when the vendored library's floor rises above it — the blind spot that produced the
bug. It parses the Mach-O in PHP (`tests/Support/MachO.php`) rather than shelling out to `vtool`, so
the guard also fires on a Linux or Windows `composer test`. See the checklist step in
`build/readme/03-sfx-maintenance.md`. Escape hatch for a self-built library: `GRAFIDA_BOSON_LIBRARY`
(feeds `ApplicationCreateInfo::$library`), which also switches the run-time gate off.

**Binaries-only build (no packaging):** `build.xml` (root) is a **Phing** buildfile whose default
target `git` (also `composer build:git`) compiles the native binary for **every** platform but stops
short of the installers/DMG — `git` depends on six per-platform targets (`git-macos-arm`,
`git-macos-x86`, `git-win-x86`, `git-linux-x86`, `git-linux-arm`, `git-phar`). **Phing is expected as a
globally-installed command** (`phing` on the PATH; it is deliberately
*not* a Composer dev dependency), so `composer build:git` just shells out to `phing git`. Because
`boson compile` builds *all* `boson.json` targets in one pass with no per-OS CLI flag, each target
shells out to `build/tasks/compile-target.php`, which filters the master `boson.json` down to the one
requested `--type`/`--arch` at runtime (pinning an explicit `root` so the throwaway single-target config
can live in `build/.temp/`), drops the stale box/entrypoint cache, then runs `boson compile
--config=<temp>`. **The application PHAR is the same for every target, so it is packed once**: the
`pack` target runs `compile-target.php --pack-only` (`boson pack`), every `git-*` target depends on it
and passes `--no-pack` (`boson compile --no-pack`, which also tells the script *not* to drop the PHAR it
is about to compile). Phing runs a shared dependency once per invocation; running the script without
either flag still packs and compiles in one go (what `build-all.sh --all` uses). `pack` depends on `prepare`
(sub-targets `prepare-composer` + `prepare-icons` + `prepare-assets`). **`prepare-composer` runs
`composer install --no-scripts` on every invocation**, so vendor/ always matches `composer.lock` after a
pull or on another machine (a stale grafida/shell-packager once failed a build with "Unknown command");
it costs under a second when there is nothing to do, and `--no-scripts` keeps the ~5 s npm
post-install-cmd out of it. The other two are guarded: they re-rasterise the icons and vendor the
front-end libraries only when their output is missing (force a re-vendor with `-Drefresh.assets=1`),
which is also how a fresh `git clone … && phing` gets its front-end libraries. Phing runs `prepare` once
per invocation.
**`windows.sign.op-item` is the one signing switch** for `package-shell-windows-x86`: the target
branches on it (`<if>`). Empty: the script runs with no signing settings and nothing is signed. Set:
a signed build, with the other `windows.sign.*` properties passed as they are; getting them right is
the maintainer's job, not the build's. (Passed positionally, a blank `op-item` used to vanish, since
Phing drops an empty `<arg>`, and the next property, `appId`, was taken for the item. Phing's
`<env>` is no way out: it pastes `export K=V;` into a shell line unquoted.) `prepare` also runs **`set-version`** (`build/tasks/set-version.php`): the **`CHANGELOG`
is the single source of truth for the version** — its topmost entry's heading ends with the version
number (the project convention, e.g. `Grafida 0.1`), and the step
stamps it into `App::VERSION` in `src/Support/App.php` before every compile (idempotent; no-ops when
already current). `GRAFIDA_VERSION` overrides the CHANGELOG. So every `git-*` build (and transitively
`package-*`/`run`) reports the CHANGELOG version in the binary and the About dialog.

**Private build configuration:** `build.xml` loads `build/build.properties` (gitignored — holds
secrets, never committed; a missing file is tolerated). The committed `build/build.sample.properties`
is the template (`cp` it to `build/build.properties` and fill in). It carries the plumbing for the
not-yet-built update mechanism: GitHub Releases (`github.organization`, `github.repository`,
`github.token` — the PAT) and CDN upload over FTP (`cdn.ftp.hostname`, `cdn.ftp.username`,
`cdn.ftp.password`, `cdn.ftp.directory`). The plan: publish a release to GitHub Releases, then use the
`build/tasks/UpdateJson.php` Phing task (organization/repository/token/outfile attributes) to fetch the
latest release's metadata into an `update.json` and upload it to the CDN. (`build/.gitignore` ignores
everything under `build/` except a whitelist, so `build.properties` is ignored automatically; the
sample is explicitly whitelisted.)

**Packaged build via Phing:** the `package` target (also `composer build:package`) builds *and*
packages every platform into `build/dist/` — it depends on six per-platform `package-*` targets
(`package-macos-arm/-x86`, `package-win-x86`, `package-linux-x86/-arm`, `package-phar`), and each
`package-X` depends on its matching `git-X` (so it compiles the binary first) then shells out to the
relevant `scripts/make-*.sh` helper. This is the Phing equivalent of `scripts/build-all.sh`
(`composer build`); both produce the same artifacts through the same per-platform scripts, so use
whichever entry point you prefer (`build-all.sh` adds a tolerant warn-and-continue summary across all
platforms, the Phing targets let you build/package a single platform on demand).

**Run on this host:** the `run` target (also `composer start`) compiles the binary for the *current*
host and launches it. Since Phing `depends` is static, `run` resolves the host's OS+arch at runtime
(`<os family>` + `uname -m`) into `run.*` properties, dispatches the matching `git-*` compile with
`<phingcall>`, then executes the bare self-contained binary directly from its output dir (e.g.
`build/macos/aarch64/grafida`; `grafida.exe` on Windows) — *not* the `.app`/installer, which belong to
the `package-*` targets. macOS arm64→`git-macos-arm`, macOS x86_64→`git-macos-x86`,
Linux aarch64→`git-linux-arm`, Linux x86_64→`git-linux-x86`, Windows→`git-win-x86`; an unrecognised
host fails with a clear message.

**Tests:** the `tests` target (depends only on `prepare-composer`, not the full `prepare`) runs
`composer test` — the PHPUnit suites (unit + integration + feature) **and `test:js`**. `phpunit.xml`
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
process: build+package every platform, then (1) create a **published GitHub release** with the
installers/DMGs/PHAR as assets and `RELEASENOTES.md` as the description, (2) build `grafida.json` from
that release, and (3) upload it to the CDN over **FTPS**. It needs the `github.*` + `cdn.ftp.*`
properties from `build/build.properties` (it fails early with a clear message if they're unset). Three
custom Phing tasks under `build/tasks/` (namespace `tasks\`, taskdef'd with `classpath="…/build"`)
back it, all curl-based (no extra binaries, matching `UpdateJson.php`): **`GitHubRelease`** (creates a
draft release, uploads each nested-`<fileset>` asset, then publishes — so a partial release is never
visible), **`UpdateJson`** (fetches the latest release's metadata into `grafida.json`), and
**`FtpsUpload`** (uploads over explicit FTPS — `CURLUSESSL_ALL`). The version comes from the CHANGELOG
via `set-version.php --print`. `UpdateJson` treats a release as downloadable when it has any asset
ending in `.zip`/`.exe`/`.dmg`/`.tar.gz` (`UpdateJson::ASSET_EXTENSIONS` / `isDownloadableAsset()`); its
`grafida.json` `download` field is provisionally the **first** such asset — a real per-platform download
map is for when the update mechanism itself is built.

## Release procedure

The exact procedure that worked for 0.1; it refines the generic release-workflow guidance.
The version comes from the CHANGELOG's top entry; `-Dversion=X.Y.Z` overrides it and stamps
`App::VERSION`.

1. **Release notes.** `RELEASENOTES.md` (GitHub-flavoured Markdown) must target the upcoming
   version, reviewed against the CHANGELOG. Its Downloads table must use **linked** filenames
   (see "Release-notes downloads" below).
2. **Check credentials.** `build/build.properties` must have non-blank `github.*` and `cdn.ftp.*`
   values, or the release fails early.
3. **Full packaging build.** Clear stale `build/dist/*` first, then `phing package -Dversion=X.Y.Z`.
   This produces the real installers/DMG/tarballs/PHAR (macOS DMGs notarised, Windows
   Authenticode-signed). ⚠️ **Do NOT use `phing git` for the test build** — it emits bare binaries
   only, so installation cannot be tested.
4. ⛔ **HUMAN-VERIFICATION BREAKPOINT — the key step.** Stop after step 3 and wait for the human to
   install and test the **fully packaged artifacts in `build/dist/`** (the DMG/installer), *not*
   the bare `phing git` binaries. Do not tag or publish until they confirm. The breakpoint belongs
   here, after full packaging — putting it earlier, at the bare-binary stage, tests nothing that
   a user will actually run.
5. **Commit** — only if the working tree is dirty. `set-version` is idempotent, so a CHANGELOG that
   already names the version leaves nothing to commit and this step is skipped.
6. **Tag:** `git tag X.Y.Z -sm "Tagging X.Y.Z"` (signed), then `git push origin X.Y.Z`.
7. **Publish:** `phing release -Dversion=X.Y.Z` — repackages all platforms, creates and publishes a
   GitHub release with the six assets, generates `grafida.json` and uploads it over FTPS to the
   BunnyCDN `updates/` directory.
8. **Verify:** `curl https://grafida.app/updates/grafida.json` reports the new version, and
   `gh release view X.Y.Z --repo grafida/grafida` shows `draft=false` with all six assets.

⚠️ **Publishing the documentation to Grafida.app is not part of this and must not be added to it.**
`scripts/upload-docs.sh` (`composer docs:publish`) uploads `docs/` over SFTP whenever the pages
change; the app serves Help out of its own binary, so the site and the shipped build are
independent, and folding the upload into `release` would leave the public documentation stale
between versions. That is also why it is not a Phing target. See
`build/readme/05-documentation-publishing.md` and `.claude/rules/documentation.md`.

⚠️ **Non-fatal noise to expect:** the Windows `signtool verify` step prints `Timestamp Server
Signature verification: failed` / `Signature verification: failed` when run on macOS. The Azure
Trusted Signing signature **is** applied (the log shows `Signed: …Setup.exe`); this is a local
trust-chain resolution quirk, not a signing failure. Do not treat it as a build break.

The whole flow builds no feature branch — commit on `main`.

## Release-notes downloads

In `RELEASENOTES.md` the Downloads table must **link** each filename to its GitHub release asset,
not list bare filenames, so a reader can click straight through. The URL is entirely predictable —
`https://github.com/grafida/grafida/releases/download/<TAG>/<FILENAME>`, where `<TAG>` is the version
number (e.g. `0.1`). So a row is:

```
| macOS (Apple Silicon) | [`Grafida-<v>-macos-arm64.dmg`](https://github.com/grafida/grafida/releases/download/<v>/Grafida-<v>-macos-arm64.dmg) |
```

Do this for every platform row when writing release notes for a new version.

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
