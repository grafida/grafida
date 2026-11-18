# Native shell, worker process and packages

Read before touching `shell.json`, `src/worker.php`, the shell/worker wire protocol, file/folder dialogs, or the per-platform package layouts.

## How the native shell works here (important)

Grafida runs on its **own native shell**, grafida/shell (Rust; umbrella gh-82, switch-over gh-92).
Up to 0.3 it ran on Boson; Boson the runtime is gone from the tree, `composer.json` and every
artefact, and so is its **scheme**: the page origin is byte-identical `shell://app` on every
platform (`shell.json`'s `"scheme": "shell"`), because `Media\LocalMediaUrl` writes
`shell://app/api/media/{id}/raw?rev=…` into stored drafts and `.grafida` exports,
`Html\InlineMedia::LOCAL_URL_PREFIX` parses it back, `localStorage` is per-origin, and users'
local AI servers may allow-list that origin. Never rename it again. 0.3 stored `boson://app/…`:
migration `11_local_media_scheme_shell.sql` rewrites it in `drafts`, and `InlineMedia::isLocalUrl()`
/ `idFromLocalUrl()` still read the legacy prefix (an old `.grafida` export), never write it.

**Two processes.** The shell owns the window and the webview (WKWebView, WebKitGTK 4.1, WebView2) and
answers `shell://app/_native/*` itself (dialogs, open-url, window theme, quit, info); every other
`shell://` request is a frame on the stdin of **one worker process**, Grafida's PHP back-end, and
the answer comes back on its stdout. The worker is **`src/worker.php`** (the container + shell-php's
`Worker::run()`), the only entry point: packaged, the phpmicro sibling-phar SFX stub
(`grafida-worker`) runs it out of its sibling `grafida-worker.phar`; in dev mode it is the system
`php src/worker.php`. Requests are serialised in the worker, as they always were, but PHP never runs
on the UI thread any more, so a slow request no longer freezes the window. The HTTP types, the frame
codec, the stdio loop and `StaticFiles` come from the MIT package
**[grafida/shell-php](https://github.com/grafida/shell-php)**, which speaks the wire protocol specified
in **grafida/shell** (`protocol/v1.md`, with conformance fixtures). `composer test:pipe`
(`tests/Pipe/`) spawns the worker over real pipes: every conformance fixture against `src/worker.php`,
then Feature-suite requests replayed through a worker and through `Kernel::handle()` in process,
which must answer byte for byte the same. shell-php is pinned to an **exact tag** (`0.2.1`) through a
`vcs` repository entry in `composer.json`, until it is on Packagist. To change shell-php and Grafida
together, point that entry at a local **path repository** (`../shell-php`, `"options": {"symlink":
false}`) and require `dev-main`. Tag shell-php before committing Grafida, and never commit the path
entry: it breaks every checkout that lacks the sibling clone. shell-php's `Request`/`Response` keep
positional constructors and `Headers` iterates one `name => value` pair per value, which is what let
the Feature suite (the API contract) switch type with no assertion edits.

**The manifest is the repo-root `shell.json`** (id `app.grafida`, title, window, worker,
`noStorePaths: ["/api/"]`, `devtools: "env:GRAFIDA_DEBUG"`, the reserved Cmd/Ctrl+Shift+V chord), read
by the shell in dev mode and shipped beside it in every package. **Dev mode** is `composer shell:dev`
(also `composer start`, `phing run`): it builds the sibling `../shell` clone with the debug automation
channel and starts it with `--dev .`, which reads `shell.json` and runs `php src/worker.php`, so a PHP
edit takes effect when the worker restarts and nothing is compiled. The automation channel
(`composer shell:dev -- --automation`, then `../shell/scripts/dev/automation.py eval|screenshot|pick`)
is how the real window is driven from here; see grafida/shell's CLAUDE.md. ⚠️ **No release shell
carries the automation channel, and no build opens a remote-debugging port**: packaged builds are
verified with a separate debug shell (the shell's `debug-automation-*` workflow artifacts) swapped into
a copy of the installed app. Run dev mode with `HOME`/`XDG_*` pointed at a throwaway directory unless
you mean to use your own data.

**What the shell does that PHP used to** (deleted with Boson, gh-92): a too-old macOS is refused by
`LSMinimumSystemVersion` 12.0, which LaunchServices enforces; a missing WebKitGTK by the Linux
launcher; a missing WebView2 by the shell and the Windows installer; `WebContinuousSpellCheckingEnabled`
is set by the shell in process; the Windows worker is a GUI-subsystem SFX started with
`CREATE_NO_WINDOW`, so no console exists to hide. What is left to PHP is a worker that cannot start
(see `src/Startup/`).

**Packages**, all built on the Mac by **grafida/shell-packager** (a dev dependency pinned to an exact
tag) over the repo-root **`shell-package.json`**, each with a `.sha256` beside it: two macOS disk
images (`Grafida-<v>-macos-arm64.dmg`, `-macos-amd64.dmg` for Intel Macs), two Linux tarballs
(`-linux-amd64.tar.gz`, `-linux-arm64.tar.gz`) and the Windows installer
(`-windows-amd64-Setup.exe`). Every fetched binary is pinned by tag + SHA-256 in `shell-package.json`;
the detail, the per-platform layouts and their tests are in `.claude/rules/build-and-packaging.md`.
- **macOS:** `Grafida.app`, `CFBundleIdentifier` **`app.grafida`** (the manifest id), one bundle per
  architecture. ⚠️ **x86_64 is tested only under Rosetta 2** in Apple Silicon guests; Intel Macs are
  deprecated when macOS 28 drops Intel and Rosetta 2, and dropped when macOS 29 lands (the update
  check's `requires` then stops offering them releases). ⚠️ **Entitlements are per binary and each one
  is kept only because a test fails without it**: the shell has none, the worker only
  `com.apple.security.cs.allow-jit` (`build/macos/shell-worker.entitlements`), without which PCRE's JIT
  cannot map memory under the hardened runtime. The shell has a real menu bar: `shell:about` opens the
  SPA's About dialog, and the editor maps `beforeinput` `historyUndo`/`historyRedo` to TinyMCE's
  history (`.claude/rules/spa-frontend.md`).
- **Linux:** a tarball holding the static launcher (`grafida`), the shell (`grafida-shell`), the SFX
  stub and its PHAR, `shell.json`, `app.grafida.desktop` (named after the manifest id, the window's
  Wayland app_id), the icons and `install.sh`, which also removes Boson's `grafida.desktop` entry
  (`linux.replaces`). There is no `media/` beside it: the worker serves the SPA from inside the PHAR.
  Beside it, `composer build:shell-linux` (gh-87; not part of `composer build`) makes a `.deb`, an `.rpm`,
  an AppImage and an Arch `PKGBUILD`, all on the **system** WebKitGTK 4.1 and GTK 3 (never bundled; no
  Flatpak, see grafida/shell `docs/adr/0002-flatpak.md`); detail in `.claude/rules/build-and-packaging.md`.
- **Windows:** a per-user NSIS installer built **on the Mac** (makensis, Jsign, osslsigncode; icon and
  version resources written in PHP) from Grafida's own **`build/windows/shell-windows-installer.nsi`**. It
  **takes over Boson's identity**: `%LOCALAPPDATA%\Programs\Grafida` (honouring
  `HKCU\Software\Grafida\InstallDir`), the uninstall key `Grafida`, the Start menu entry
  `Grafida\Grafida.lnk`; before it writes anything it checks neither Grafida is running and removes a
  Boson or preview (`app.grafida`) install, leaving `%APPDATA%\Grafida` alone. Signed through Azure
  Trusted Signing when `windows.sign.op-item` is set (four signatures a build). There is no WebView2
  bootstrapper: the installer warns and links when the runtime is missing, and the shell alerts.

Request flow: shell → frame → `src/worker.php` → `Grafida\FrontController` →
`Grafida\Application\Kernel` → either `Grafida\Http\ApiController` (paths under `/api/`) or a static
asset / the SPA shell. **The kernel is a pure `Request → Response` function**, so the whole back-end is
testable without a window or a process (see `tests/Feature/ApiRoutingTest.php`).

**The composition root is a DI container, not the Kernel.** `src/worker.php` builds a
`Grafida\Application\Container` (a thin `Joomla\DI\Container` subclass — see `src/Application/`)
via `ContainerFactory::create()` and pulls `FrontController` out of it; `Kernel` is
`(StaticFiles $static, ApiController $api)`. Nothing is `new`ed ad-hoc and there is
no global/singleton database object — add a service by registering it in a **service provider**
(`src/Application/Provider/`), not by editing a constructor chain.

**Files are picked in the shell's own dialogs, never through `<input type="file">`.** The shell's
dialog (`/_native/dialog/*`, `js/shell.js` from shell-php) answers with an opaque per-session
**token, never a path**; the SPA sends it back as `X-Shell-File`, and the shell — which strips any
`X-Shell-File-Path` the page sends — forwards the resolved path as `X-Shell-File-Path`, which
`Grafida\Http\ShellFile` reads: `SettingsController::openFile()` returns that file as `{name, mime,
dataBase64}`, and the three folder exports (`.grafida` draft, Request Log, Local Media "save to disk")
take the folder over the body's `directory`. So no path ever comes from JS, and a folder pick is
`{token, name}`: pass the whole `dir` object to the export call. A dialog request without the header
answers **503** (`/api/dialog/open-file`, `/api/dialog/select-directory`): that is what a plain
browser gets, the `/verify` harness, whose `underShell()` is false. `api.openUrl()` goes to the
shell's `_native/open-url`, the clipboard for Cmd/Ctrl+Shift+V is read by the shell
(`X-Shell-Clipboard`), and the title bar follows the resolved theme (`_native/window/theme`); without
the shell, PHP's `UrlOpener` and `ClipboardService` answer instead.
