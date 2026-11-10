# SFX runtime maintenance playbook

Operational notes for the patched `micro.sfx` runtimes that make Grafida's
macOS builds code-signable. Background and rationale:
[`02-signing-architecture.md`](02-signing-architecture.md); Apple-side setup and
build recipe: [`01-macos-signing.md`](01-macos-signing.md).

The binaries are built by GitHub Actions on
[`grafida/phpmicro`](https://github.com/grafida/phpmicro) (branch
`sibling-phar`, workflow `.github/workflows/build-sfx.yml`) and published to the
rolling **`sfx-latest`** release: `macos-{aarch64,x86_64}.standard.sfx` (unix
`build` matrix) and `windows-x86_64.standard.sfx` (separate `build-windows` job —
static-php-cli uses the MSVC/php-sdk toolchain there, PE files must be `*.exe` to
run, and there is no `chmod`, so its download/build steps mirror the unix job but
the smoke test and packaging are PowerShell). `scripts/fetch-sfx.sh` downloads
all three into the gitignored `build/sfx/` — automatically from the Phing
`prepare-sfx` step and `scripts/build-all.sh`, best-effort, and it **never
overwrites an existing file** unless you pass `--force`.

## Linux runtimes (the native shell, gh-86)

The same workflow's `build-linux` job builds `linux-{x86_64,aarch64}.standard.sfx` on
`ubuntu-22.04` and `ubuntu-22.04-arm`. They are consumed only by the experimental native-shell
tarball (`composer build:shell-linux`, through grafida/shell-packager), never by Boson:
`fetch-sfx.sh` does not download them, and shell-packager caches them outside `build/sfx/`.

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
- **Every run also publishes an immutable `sfx-<YYYYMMDD>.<run>` release.** shell-packager
  consumers pin one of those by SHA-256, never `sfx-latest`, which the next push overwrites.
- **Dispatch a subset** (`gh workflow run build-sfx.yml --ref sibling-phar -f platforms=linux`) to
  rebuild Linux alone. A full run rebuilds macOS and Windows against the newest PHP 8.4 patch
  release, which changes what Boson's `release` ships. Commit a Linux-only workflow change with
  `[skip ci]`, then dispatch.

## The macOS runtime for the native shell (gh-88)

The native-shell bundle pins **`sfx-20261109.15`**: `macos-aarch64.standard.sfx`, SHA-256
`046be1292371b1b59498c5df123956c02079e704dd5877d116b8385412134af4` (PHP 8.4.27, `minos` 12.0). Those
are the exact bytes `sfx-latest` already held (built by run 15 of `build-sfx.yml`), promoted into an
immutable release rather than rebuilt, so nothing Boson ships changed.

- **Promotion is a mode of `build-sfx.yml`**: `gh workflow run build-sfx.yml -R grafida/phpmicro
  --ref sibling-phar -f platforms=promote-macos-aarch64 -f sha256=<hash>` downloads the asset from
  `sfx-latest`, checks it against the given hash **and** the published `.sha256`, runs
  `.github/scripts/check-macos-sfx.sh` on it, and publishes `sfx-<YYYYMMDD>.<run>` with
  `--latest=false`, never touching `sfx-latest`. When GitHub has no arm64 macOS runner (it happened
  four times in a row), the release can be published by hand from a Mac on a signed tag on the commit
  that built the bytes; `sfx-20261109.15` was, and its notes say so.
- **`check-macos-sfx.sh`** gates every macOS SFX: thin `EXPECT_ARCH`, `minos` ≤ 12.0 from
  `LC_BUILD_VERSION`, only system libraries linked, and every payload mode: appended; sibling
  `<self>.phar`; the bundle layout (`Contents/MacOS/<stub>` + `Contents/Resources/<stub>.phar`) behind
  an extra-INI block; a symlink from another directory; ad-hoc and hardened-runtime signatures.
- **Every non-rebuild commit to `sibling-phar` carries `[skip ci]`**: a push rebuilds and rewrites
  `sfx-latest`, and with it what Boson's next release ships.

## The Windows runtime for the native shell (gh-91)

The native-shell installer pins **`sfx-20261110.27`**: `windows-x86_64.standard.sfx`, SHA-256
`2421951f6a93ba1eb69aabd1592d2df0af030c03b059cff1fefc69f03f764146` (PHP 8.4.27, the GUI subsystem).
Unlike macOS it is a **fresh build, published immutable-only**: `sfx-latest`'s Windows asset, which
Boson ships, is untouched. Two fixes went into it:

- **`CREATE_NO_WINDOW` for the worker's children.** static-php-cli's patch makes the micro SAPI count
  as `cli` in `TSRM/tsrm_win32.c` and `ext/standard/proc_open.c`, which drops the flag PHP sets for
  every non-CLI SAPI, so a GUI worker's `proc_open`/`exec` children each got a console window.
  `.github/scripts/keep-create-no-window.php` drops those two hunks from the patch before the build.
- **The sibling `.phar` through the file's final path** (`GetFinalPathNameByHandleW`): PHP's
  `realpath()` and the stub disagreed when the path held an 8.3 short name (CI's `RUNNER~1` temp
  directory), so the offset stream hooks no longer matched and the payload read as garbage.

- **The build is a dispatch mode of `build-sfx.yml`**: `gh workflow run build-sfx.yml -R
  grafida/phpmicro --ref sibling-phar -f platforms=windows-immutable` builds and checks Windows
  alone, publishes `sfx-<YYYYMMDD>.<run>` with `--latest=false`, and never writes `sfx-latest`.
- **`check-windows-sfx.sh`** gates it, on the runner and again in both test guests' desktop
  sessions: PE32+/AMD64, the GUI subsystem, system DLLs only, no overlay; the sibling payload behind
  an extra-INI block, `ini_get()` proving it applied, a short-name path; a stub signed with a
  throwaway certificate; 50 MiB over real pipes; and no console window for `proc_open`/`exec`
  children, watched by a WinEvent hook against a control that must show one.

## When Boson bumps its PHP version

Boson's runtime PHP version and our SFX **must match the same minor** (the SFX
*is* the PHP runtime; the phar only needs a compatible engine). When a Boson
update moves to a new PHP minor (e.g. 8.4 → 8.5):

1. Edit `PHP_VERSION` in the fork's `.github/workflows/build-sfx.yml` and push
   to `sibling-phar` → CI refreshes the `sfx-latest` release automatically.
2. On the build machine: `scripts/fetch-sfx.sh --force`.
3. Rebuild + sign, and run the app once (`phing package-macos-arm`, launch).

## When Boson changes its SFX extension list

Our `EXTENSIONS` in `build-sfx.yml` mirrors Boson's *standard* macOS edition:
`STANDARD_SFX_EXTENSIONS` in
`vendor/boson-php/compiler/src/Target/MacOSBuiltinTarget.php`. The **Windows**
standard edition (`WindowsBuiltinTarget.php`) is currently **identical**, so the
single `EXTENSIONS` env feeds both the macOS and Windows jobs. After a Boson
upgrade, diff **both** constants against the workflow's list; if either changed,
update the workflow (splitting `EXTENSIONS` per-OS if they ever diverge), push,
`fetch-sfx.sh --force`, rebuild, test.

## When bumping Boson itself

1. `composer update boson-php/*` as usual, then compare (as above): PHP version,
   extension list, and skim `AssemblyTargetTask` / `FindCustomSfxPathnameTask`
   in the compiler for changes to the assembly format or the custom-`sfx`
   config key our `compile-target.php` relies on.
2. **Check the macOS library's deployment target.**
   `vtool -show-build vendor/boson-php/saucer/bin/libboson-darwin-universal.dylib`
   reports `minos` for **both** slices. Upstream builds it on `macos-latest` with
   no `CMAKE_OSX_DEPLOYMENT_TARGET`, so this silently follows whatever SDK the
   GitHub runner had and can rise on any bump — locking out every user of the
   current macOS. If it exceeds `App::MIN_MACOS`, `composer test` says so
   (`tests/Unit/Startup/MacosDeploymentFloorTest.php`): raise the constant with
   `App::MIN_MACOS_NAME`, `docs/Requirements.md` and `README.md`. Also confirm
   both `x86_64` and `arm64` slices are still there — the same test pins that,
   since losing the Intel slice would break `build/macos/amd64` with no other
   warning. ⚠️ `vtool -set-build-version` can rewrite the load command but
   **cannot** remove the libc++ symbol dependency behind it (gh-58): a patched
   library still fails to `dlopen` on the older system.
3. Rebuild, sign, notarise, and launch-test. If anything smells wrong, verify
   the stub first (stale-binary check below), then run the app with
   `MICRO_TRACE_OPEN=1` to watch the payload-offset hooks.
4. Also re-test whether the detour is still needed at all — if a stock Boson
   binary ever signs cleanly, retire the fork (see the end of 01).

## Keeping the fork healthy

* **Rebase/merge upstream** (`static-php/phpmicro`, branch `master`) into
  `sibling-phar` occasionally — at the latest when bumping the PHP version,
  since new PHP minors usually need new upstream micro fixes. The patch
  surface is deliberately tiny: `php_micro_fileinfo.c` (sibling fallback +
  payload-path override, in **both** the POSIX and `PHP_WIN32` branches of
  `micro_fileinfo_init` / `micro_get_filename`) and `php_micro_hooks.c`
  (`MICRO_TRACE_OPEN`). The Windows fallback tries `<self-without-.exe>.phar`
  (`grafida.exe` → `grafida.phar`) then `<self>.phar`; it has no macOS
  `../Resources/` candidate (bundle-specific). It sets `_micro_payload_path` to
  the UTF-8 path because the plain-file stream hooks match the payload by the
  narrow `micro_get_filename()`.
* Every push to `sibling-phar` rebuilds and republishes the SFX binaries. CI
  smoke-tests the payload modes on every platform — appended + `<self>.phar` on
  all, plus `../Resources/` on unix (macOS bundle layout) — so a build from
  unpatched sources cannot reach the release.
* The workflow needs no secrets; it authenticates static-php-cli's tool
  downloads with the default `GITHUB_TOKEN` (without it, SPC hits the runners'
  anonymous GitHub API rate limit and fails in `doctor`).
* The `build-windows` job installs static-php-cli's dependencies with
  `composer install --no-dev` — a GPG-verified dev dependency fails to install
  on the Windows runner, and nothing in the SFX build needs it.

## Building the SFX locally (CI bypass)

Full recipe in 01. Gotchas that will bite you:

* static-php-cli's source is named **`php-micro`** — a `micro:` prefix in
  `-G`/`-L` is **silently ignored** and you build *stock* phpmicro.
* SPC caches aggressively. When iterating on the C patch:
  `rm -rf downloads/php-micro source/php-src buildroot` before rebuilding.
* For local patch iteration prefer `-L "php-micro:/path/to/phpmicro"` (uses
  your working tree directly, no push needed). Note `-L` puts nothing in
  `downloads/` — verify the result *behaviourally* (sibling smoke test), not by
  grepping downloads.

## Troubleshooting checklist

1. **Stale binary?** The classic trap: a failed compile leaves the previous
   binary in `build/<os>/<arch>/`, and everything downstream happily signs the
   wrong bits. Verify:
   `head -c $(stat -f%z build/sfx/<sfx>) build/macos/<dir>/grafida | md5` must
   equal `md5 build/sfx/<sfx>`. (`compile-target.php` pre-cleans output dirs
   precisely to prevent this — do not bypass it by calling `boson compile`
   directly.)
   ⚠️ On Windows this comparison only works on the **unsigned** binary. Signing
   rewrites the PE checksum and the security-directory entry, both of which live
   inside the SFX prefix, so a *signed* stub's prefix hash will never equal the
   raw SFX. Compare the unsigned compiled `grafida.exe` instead — a mismatch
   there is a real stale-binary problem; a mismatch after signing is expected.
2. **Phantom phar corruption** (`zlib: data error`, "actual filesize
   mismatch")? Some reads are missing the payload offset. Run with
   `MICRO_TRACE_OPEN=1` and check every open of the payload path reports
   `match=1`. Historic cause: a non-canonical (`..`) payload path.
3. **App won't start after splitting?** The stub was probably built from a
   stock SFX (no sibling fallback). `strings <stub> | grep -c "next to this
   executable"` — 0 means stock.
   ⚠️ If you grep the **binary directly** instead of piping through `strings`,
   use `grep -a` (`--text`). BSD grep (the macOS default) treats the combined
   binary as binary data and stays silent about matches, so a sibling-capable
   stub reads as stock and you chase a phantom build problem.
4. **`codesign` complains about a subcomponent?** A data file crept into
   `Contents/MacOS`. Only signed Mach-O files may live there.
5. **Verification commands** for a finished build: see "Verifying a finished
   build" in 01 (`codesign --verify --deep --strict`, `spctl -a --type install`,
   `stapler validate`).
