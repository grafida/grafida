# SFX runtime maintenance playbook

Operational notes for the patched `micro.sfx` runtimes that Grafida's worker
runs on, and that make its macOS and Windows packages code-signable. Background
and rationale: [`02-signing-architecture.md`](02-signing-architecture.md);
Apple-side setup and how the build signs:
[`01-macos-signing.md`](01-macos-signing.md); Windows signing:
[`04-exe-signing-on-macos.md`](04-exe-signing-on-macos.md).

The binaries are built by GitHub Actions on
[`grafida/phpmicro`](https://github.com/grafida/phpmicro) (branch
`sibling-phar`, workflow `.github/workflows/build-sfx.yml`):

* `macos-{aarch64,x86_64}.standard.sfx` — the unix `build` matrix;
* `windows-x86_64.standard.sfx` — a separate `build-windows` job
  (static-php-cli uses the MSVC/php-sdk toolchain there, PE files must be
  `*.exe` to run, and there is no `chmod`, so its download/build steps mirror the
  unix job but the smoke test and packaging are PowerShell);
* `linux-{x86_64,aarch64}.standard.sfx` — the `build-linux` job.

Every run publishes an **immutable `sfx-<YYYYMMDD>.<run>` release**. Grafida
pins one of those per platform and architecture, by URL and SHA-256, in
`shell-package.json` (`runtimes.<linux|macos|windows>.sfx`), and
grafida/shell-packager downloads it, checks the hash on every use and caches it
in `~/.cache/grafida-shell-packager` (`$XDG_CACHE_HOME`, or
`SHELL_PACKAGER_CACHE`). A mismatch is fatal. The rolling **`sfx-latest`**
release, which most runs also rewrite, is **no longer used by anything Grafida
ships** (Boson's build downloaded it, up to 0.3). There is no `build/sfx/`.

## Current pins

| Platform | Release | Asset | Notes |
| --- | --- | --- | --- |
| Linux x86_64, aarch64 | `sfx-20261109.16` | `linux-{arch}.standard.sfx` | glibc, 2.35 floor (gh-86) |
| macOS arm64 | `sfx-20261109.15` | `macos-aarch64.standard.sfx` | a promotion of `sfx-latest`'s bytes (gh-88) |
| macOS x86_64 | `sfx-20261111.28` | `macos-x86_64.standard.sfx` | a promotion of `sfx-latest`'s bytes, checked natively on `macos-15-intel` (gh-92) |
| Windows x86_64 | `sfx-20261110.27` | `windows-x86_64.standard.sfx` | a fresh immutable-only build, the GUI subsystem (gh-91) |

`shell-package.json` holds the hashes and is the only source of truth for them.
When the architectures of one platform come from different releases (macOS),
`url` is an object with one URL per architecture instead of one `{arch}`
template.

## Moving a pin

A new pin is always a **new immutable release per platform**, and its hash is
**never typed**: it is computed from the downloaded bytes and proven with
`shell-packager fetch` (one hand-typed digit was caught that way in
shell-packager's own fixture). A `.sha256` published beside the asset is not a
source either.

1. **Get the release.** Dispatch `build-sfx.yml` with a `platforms` mode:

   | `platforms=` | What it does |
   | --- | --- |
   | `all`, `linux`, `macos`, `windows` | builds those platforms, publishes an immutable release **and** rewrites `sfx-latest` |
   | `windows-immutable` | builds and checks Windows alone, publishes only the immutable release |
   | `promote-macos-aarch64`, `promote-macos-x86_64` (with `-f sha256=<hash>`) | builds nothing: checks `sfx-latest`'s asset against the stated hash **and** the published `.sha256`, runs `check-macos-sfx.sh` on it, publishes it alone, immutable |

   ```bash
   gh workflow run build-sfx.yml -R grafida/phpmicro --ref sibling-phar -f platforms=linux
   gh workflow run build-sfx.yml -R grafida/phpmicro --ref sibling-phar -f platforms=windows-immutable
   gh workflow run build-sfx.yml -R grafida/phpmicro --ref sibling-phar \
     -f platforms=promote-macos-x86_64 -f sha256=<hash>
   ```

   There is no immutable-only build mode for macOS or Linux. A build publishes
   from the newest PHP 8.4 patch release, so a rebuild is a different runtime,
   not a refresh of the same one.
2. **Hash the bytes.** Download the asset into an empty directory and hash it:

   ```bash
   gh release download sfx-<YYYYMMDD>.<run> -R grafida/phpmicro -p '<asset>' -D <empty-dir>
   shasum -a 256 <empty-dir>/<asset>
   ```

3. **Update `shell-package.json`**: the release in the URL and the hash for
   every architecture you moved. Copy the hash from the `shasum` output.
4. **Prove every pin**, per architecture and platform:

   ```bash
   vendor/bin/shell-packager fetch --config=shell-package.json --arch=x86_64  --platform=linux
   vendor/bin/shell-packager fetch --config=shell-package.json --arch=aarch64 --platform=linux
   vendor/bin/shell-packager fetch --config=shell-package.json --arch=aarch64 --platform=macos
   vendor/bin/shell-packager fetch --config=shell-package.json --arch=x86_64  --platform=macos
   vendor/bin/shell-packager fetch --config=shell-package.json --arch=x86_64  --platform=windows
   ```

5. **Build and test the package.** The build runs the packaged worker (an ini
   probe and the protocol's `ready` handshake) on a host that can run it; then
   run `tests/Pipe` against it with `GRAFIDA_PIPE_WORKER=<packaged worker>
   composer test:pipe` (on Windows, in the test guests).

## Linux runtimes (gh-86)

The `build-linux` job builds `linux-{x86_64,aarch64}.standard.sfx` on
`ubuntu-22.04` and `ubuntu-22.04-arm`.

- **glibc, linked dynamically (2.35 floor); everything else static.** A static musl SFX has no NSS,
  so `.local` (mDNS), systemd-resolved and libvirt names would not resolve. Three settings are needed
  together, each for a reason that is easy to undo by accident:
  `SPC_TOOLCHAIN=StaticPHP\Toolchain\GccNativeToolchain` (with any `SPC_TARGET` set, static-php-cli
  ignores `SPC_LIBC`, and its `config/env.ini` sets `SPC_TARGET=<arch>-linux-musl` by default),
  `SPC_TARGET=native-native-gnu` (so the rest of static-php-cli treats the libc as glibc), and
  `SPC_EXTRA_PHP_VARS=--enable-micro` (static-php-cli hard-codes `--enable-micro=all-static` on
  Linux; the later value wins). Not the Zig toolchain's `gnu.2.35` target: phpmicro links libresolv
  with `-Wl,-Bstatic,-lresolv,-Bdynamic`, which `zig cc` rejects.
- `.github/scripts/check-linux-sfx.sh` gates publishing: only glibc and libgcc_s may be NEEDED,
  with no symbol above GLIBC_2.35, and every payload mode must work: appended; sibling
  `<self>.phar`; a PHAR behind an extra-INI block (`ini_get()` must read the value back); a
  symlinked stub; NSS. Until gh-86 the sibling patch had never been smoke-tested on Linux.
- **Dispatch a subset** (`platforms=linux`) to rebuild Linux alone; a full run rebuilds every
  platform. Commit a Linux-only workflow change with `[skip ci]`, then dispatch.

## macOS runtimes (gh-88, gh-92)

The arm64 bundle pins **`sfx-20261109.15`**: `macos-aarch64.standard.sfx`, SHA-256
`046be1292371b1b59498c5df123956c02079e704dd5877d116b8385412134af4` (PHP 8.4.27, `minos` 12.0). Those
are the exact bytes `sfx-latest` already held (built by run 15 of `build-sfx.yml`), promoted into an
immutable release rather than rebuilt.

The x86_64 bundle pins **`sfx-20261111.28`**: `macos-x86_64.standard.sfx`, SHA-256
`a89695ce2b64d124d873b3b8f7291820eedf755a1db58d548c6bf3d4f556d8c8`, likewise a promotion of the
bytes `sfx-latest` held, checked by `check-macos-sfx.sh` on `macos-15-intel` (its release notes name
the run).

- **Promotion is a mode of `build-sfx.yml`**: `gh workflow run build-sfx.yml -R grafida/phpmicro
  --ref sibling-phar -f platforms=promote-macos-aarch64 -f sha256=<hash>` (or
  `promote-macos-x86_64`) downloads the asset from `sfx-latest`, checks it against the given hash
  **and** the published `.sha256`, runs `.github/scripts/check-macos-sfx.sh` on it, and publishes
  `sfx-<YYYYMMDD>.<run>` with `--latest=false`, never touching `sfx-latest`. When GitHub has no arm64
  macOS runner (it happened four times in a row), the release can be published by hand from a Mac on
  a signed tag on the commit that built the bytes; `sfx-20261109.15` was, and its notes say so.
- **The x86_64 check always runs natively on `macos-15-intel`**, whatever `runner` says, because
  the payload modes execute the file. Run by hand on Apple Silicon, `EXPECT_ARCH=x86_64
  check-macos-sfx.sh` needs Rosetta 2 and fails, after the static checks, without it.
- **`check-macos-sfx.sh`** gates every macOS SFX: thin `EXPECT_ARCH`, `minos` ≤ 12.0 from
  `LC_BUILD_VERSION`, only system libraries linked, and every payload mode: appended; sibling
  `<self>.phar`; the bundle layout (`Contents/MacOS/<stub>` + `Contents/Resources/<stub>.phar`) behind
  an extra-INI block; a symlink from another directory; ad-hoc and hardened-runtime signatures.
  shell-packager checks the `minos` again against the bundle's `LSMinimumSystemVersion` (12.0).

## The Windows runtime (gh-91)

The installer pins **`sfx-20261110.27`**: `windows-x86_64.standard.sfx`, SHA-256
`2421951f6a93ba1eb69aabd1592d2df0af030c03b059cff1fefc69f03f764146` (PHP 8.4.27, the GUI subsystem).
Unlike macOS it is a **fresh build, published immutable-only**: `sfx-latest`'s Windows asset was
untouched. Two fixes went into it:

- **`CREATE_NO_WINDOW` for the worker's children.** static-php-cli's patch makes the micro SAPI count
  as `cli` in `TSRM/tsrm_win32.c` and `ext/standard/proc_open.c`, which drops the flag PHP sets for
  every non-CLI SAPI, so a GUI worker's `proc_open`/`exec` children each got a console window.
  `.github/scripts/keep-create-no-window.php` drops those two hunks from the patch before the build;
  the step fails if a static-php-cli bump changes them.
- **The sibling `.phar` through the file's final path** (`GetFinalPathNameByHandleW`): PHP's
  `realpath()` and the stub disagreed when the path held an 8.3 short name (CI's `RUNNER~1` temp
  directory), so the offset stream hooks no longer matched and the payload read as garbage.

- **The build is a dispatch mode of `build-sfx.yml`**: `gh workflow run build-sfx.yml -R
  grafida/phpmicro --ref sibling-phar -f platforms=windows-immutable` builds and checks Windows
  alone, publishes `sfx-<YYYYMMDD>.<run>` with `--latest=false`, and never writes `sfx-latest`.
- **`check-windows-sfx.sh`** gates it, on the runner and again in both test guests' desktop
  sessions: PE32+/AMD64, the GUI subsystem, system DLLs only (so no Visual C++ runtime), no overlay;
  the sibling payload behind an extra-INI block, `ini_get()` proving it applied, a short-name path; a
  stub signed with a throwaway certificate; 50 MiB over real pipes; and no console window for
  `proc_open`/`exec` children, watched by a WinEvent hook against a control that must show one.

## Changing the PHP version or the extension list

`PHP_VERSION` (8.4) and `EXTENSIONS` in `build-sfx.yml` are Grafida's own choice. The list was
first copied from Boson's *standard* macOS edition; there is no Boson to mirror any more. One
`EXTENSIONS` feeds every job. The SFX *is* the worker's PHP runtime, so the version must satisfy
`composer.json`'s `php` constraint (`^8.4`).

1. Edit `PHP_VERSION` or `EXTENSIONS` in the fork's `build-sfx.yml` and commit it to
   `sibling-phar`.
2. Get a new immutable release for **every** platform and move every pin ("Moving a pin" above).
3. Build every package, run the worker checks and `tests/Pipe` against each packaged worker, and
   launch-test.

## Keeping the fork healthy

* **Rebase/merge upstream** (`static-php/phpmicro`, branch `master`) into
  `sibling-phar` occasionally — at the latest when bumping the PHP version,
  since new PHP minors usually need new upstream micro fixes. The patch
  surface is deliberately tiny: `php_micro_fileinfo.c` (sibling fallback +
  payload-path override, in **both** the POSIX and `PHP_WIN32` branches of
  `micro_fileinfo_init` / `micro_get_filename`) and `php_micro_hooks.c`
  (`MICRO_TRACE_OPEN`). The Windows fallback tries `<self-without-.exe>.phar`
  (`grafida-worker.exe` → `grafida-worker.phar`) then `<self>.phar`; it has no
  macOS `../Resources/` candidate (bundle-specific). It sets `_micro_payload_path`
  to the UTF-8 path because the plain-file stream hooks match the payload by the
  narrow `micro_get_filename()`.
* **Every push to `sibling-phar` rebuilds every platform** and rewrites
  `sfx-latest`. It cannot move a Grafida pin, but it costs a full CI run, so a
  commit that is not meant to rebuild (a check script, a doc, the workflow
  itself) carries **`[skip ci]`** (the fork's own rule, in its `AGENTS.md`).
* CI smoke-tests the payload modes on every platform — appended + `<self>.phar`
  on all, plus `../Resources/` on macOS (bundle layout) — so a build from
  unpatched sources cannot reach a release.
* The workflow needs no secrets; it authenticates static-php-cli's tool
  downloads with the default `GITHUB_TOKEN` (without it, SPC hits the runners'
  anonymous GitHub API rate limit and fails in `doctor`).
* The `build-windows` job installs static-php-cli's dependencies with
  `composer install --no-dev` — a GPG-verified dev dependency fails to install
  on the Windows runner, and nothing in the SFX build needs it.

## Building the SFX locally (CI bypass)

`build-sfx.yml` is the authoritative recipe (the Boson-era macOS commands are in
01 at the tag `0.3`, "Signing works — via the patched SFX runtime", step 1). Gotchas that will bite you:

* static-php-cli's source is named **`php-micro`** — a `micro:` prefix in
  `-G`/`-L` is **silently ignored** and you build *stock* phpmicro.
* SPC caches aggressively. When iterating on the C patch:
  `rm -rf downloads/php-micro source/php-src buildroot` before rebuilding.
* For local patch iteration prefer `-L "php-micro:/path/to/phpmicro"` (uses
  your working tree directly, no push needed). Note `-L` puts nothing in
  `downloads/` — verify the result *behaviourally* (sibling smoke test), not by
  grepping downloads.
* To package a local build, any shell-packager command that downloads runtimes
  takes `--local=sfx=<path>` (also `shell`, `launcher`). It is **UNPINNED**,
  announced on every use, and for testing an unreleased build only; ship only a
  pinned release. `scripts/make-windows-installer.sh` passes its arguments to
  shell-packager; for the other platforms run `vendor/bin/shell-packager`
  directly.

## Troubleshooting checklist

1. **Phantom phar corruption** (`zlib: data error`, "actual filesize
   mismatch")? Some reads are missing the payload offset. Run with
   `MICRO_TRACE_OPEN=1` and check every open of the payload path reports
   `match=1`. Historic cause: a non-canonical (`..`) payload path; on Windows,
   an 8.3 short name in the path.
2. **The worker won't start from its sibling PHAR?** The stub was probably built
   from a stock SFX (no sibling fallback), e.g. a `--local` one.
   `strings <stub> | grep -c "next to this executable"` — 0 means stock.
   ⚠️ If you grep the **binary directly** instead of piping through `strings`,
   use `grep -a` (`--text`). BSD grep (the macOS default) treats the binary as
   binary data and stays silent about matches, so a sibling-capable stub reads
   as stock and you chase a phantom build problem.
3. **An ini setting does not apply?** phpmicro silently ignores a malformed
   extra-INI block. shell-packager's worker check reads every `phar.ini` setting
   back before it writes a package; on a host that cannot run the worker it says
   it skipped the check.
4. **`codesign` complains about a subcomponent?** A data file crept into
   `Contents/MacOS`. Only signed Mach-O files may live there.
5. **Verification commands** for a finished macOS build: see "Verifying a
   finished build" in 01.
