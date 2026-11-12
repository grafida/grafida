# Code-signing architecture — why there is a phpmicro fork

This is the anchor document for how Grafida's binaries become code-signable, why
a **forked phpmicro** is involved, and which design decisions were made on
purpose. Read this before touching anything signing-related, bumping a runtime
pin, or wondering "can we get rid of that fork yet?". Companion documents:

* [`01-macos-signing.md`](01-macos-signing.md) — the Apple-side setup
  (certificates, notarisation), how the build signs and notarises, and (as
  history) the low-level analysis of why a stock Boson binary cannot be signed.
* [`03-sfx-maintenance.md`](03-sfx-maintenance.md) — the operational playbook
  (bumping a runtime pin, the PHP version and extension list, rebasing the fork,
  troubleshooting).
* [`04-exe-signing-on-macos.md`](04-exe-signing-on-macos.md) — Windows
  Authenticode through Azure Trusted Signing, from the Mac.

From 0.4 (grafida/grafida#92) every release artefact is the native shell's,
built by grafida/shell-packager. Up to 0.3 Grafida shipped a Boson-compiled app;
how that build used the fork is kept at the end, as history.

## The problem, in one paragraph

Grafida's PHP worker runs on **phpmicro**: a native Mach-O/PE stub (`micro.sfx`,
the PHP runtime) that runs a PHAR. phpmicro's stock layout is a
**self-executable**: the PHAR **concatenated after the stub**, and therefore
*after the binary's code-signature region*. Apple's `codesign` requires the
signature to be the trailing content of the file, so the combined binary fails
signing with "main executable failed strict validation". The payload cannot be
moved inside the Mach-O either (the Node.js/postject trick), because stock
phpmicro locates it as "Mach-O image end → EOF" — the two constraints are
mutually exclusive. On Windows, Authenticode appends its certificate table at
EOF, *past* the PHAR, and a PHAR stores its own signature as its last bytes, so
the signed binary dies at startup with "broken signature". Full empirical
analysis: `01-macos-signing.md`, "Why a stock Boson binary cannot be signed"
(macOS), and `04-exe-signing-on-macos.md` (Windows).

## The solution, in one paragraph

We patch **phpmicro** (the ~5-file C SAPI that is the stub) with a small,
additive fallback: **when the executable carries no appended payload, load it
from a sibling file** — `"<self>.phar"`, then `"../Resources/<basename>.phar"`
(the macOS app-bundle layout); on Windows `<self-without-.exe>.phar`, then
`<self>.phar`. shell-packager never builds a combined binary: it ships the
**bare SFX stub plus a separate `<name>.phar`**, signs only the code, and the
whole package signs, notarises and staples like any normal app. Appended-payload
mode is untouched in the fork.

⚠️ **The stub and its PHAR are two files and must stay two.** A signature
appended to a combined binary lands past the PHAR and corrupts it. shell-packager
refuses to sign a stub that still carries an appended payload, on macOS
(`MachO`) and Windows (its PE reader) alike. Linux has no signature gate, but
ships the same layout.

## The moving parts

```
grafida/phpmicro (branch sibling-phar)          ← the C patch lives here
  └─ .github/workflows/build-sfx.yml            ← CI: builds micro.sfx per platform
       │  (stock static-php-cli, pointed at the repo itself via -L)
       ▼
  GitHub release sfx-<YYYYMMDD>.<run>           ← IMMUTABLE, one per run or promotion
       │                                           (sfx-latest is rolling; nothing Grafida
       │                                           ships uses it any more)
       ▼  shell-package.json `runtimes`         ← URL + SHA-256 per platform and arch,
       │                                           beside the pinned grafida/shell binaries
       ▼  vendor/bin/shell-packager             ← downloads, checks the SHA-256 on every use,
       │                                           caches in ~/.cache/grafida-shell-packager
       │                                           (never build/sfx/); builds the worker PHAR
       │                                           (bin/worker.php + src + assets …) behind
       │                                           phpmicro's extra-INI block (`phar.ini`)
       │
       ├─▼  macos-app + macos-dmg               ← Grafida.app per arch: stub in Contents/MacOS,
       │                                           grafida-worker.phar in Contents/Resources;
       │                                           signs stub → shell → bundle, notarises the DMG
       │                                           (scripts/make-macos-dmg.sh)
       ├─▼  windows-installer                   ← tree: Grafida.exe, grafida-worker.exe +
       │                                           grafida-worker.phar; resources written in PHP;
       │                                           Jsign signs shell, stub, uninstaller, installer
       │                                           (scripts/make-windows-installer.sh)
       └─▼  linux-tarball                       ← grafida-worker + grafida-worker.phar, unsigned
                                                   (scripts/make-linux-tarball.sh)
       ▼
  build/dist/Grafida-<v>-{macos-<arm64|amd64>.dmg, windows-amd64-Setup.exe,
                          linux-<amd64|arm64>.tar.gz} (+ .sha256)
```

Entry points: `phing package` (targets `package-macos-arm`, `package-macos-x86`,
`package-windows-x86`, `package-linux-x86`, `package-linux-arm`) or `composer
build` (`scripts/build-all.sh`); everything is built on the Mac. Details:
macOS in [`01-macos-signing.md`](01-macos-signing.md), Windows in
[`04-exe-signing-on-macos.md`](04-exe-signing-on-macos.md).

**The one Windows-only subtlety (it cost a debugging cycle):** Authenticode does
not sign in place — it appends a **certificate table at EOF**, *past* the stub's
last PE section. So a bare **and signed** stub has `file size > section end`.
phpmicro's original `_micro_init_sfxsize()` computed the SFX size from sections
only, so `filesize <= sfxsize` came out false and the sibling fallback never
fired — the runtime tried to map the *certificate* as the phar. The fork's
`_micro_init_sfxsize()` now folds the `IMAGE_DIRECTORY_ENTRY_SECURITY` data
directory (a raw file offset+size, appended at EOF) into `sfxsize`, so the value
reflects the true end of the executable image — the direct analogue of macOS's
`__LINKEDIT` already covering its signature. The fork CI's **signed-sibling smoke
test** (self-sign the test stub, then assert it still loads its sibling phar)
guards this exact case; without it the regression is invisible until a real signed
Windows run. The signature rides on the stub only, and the runtime knows to look
past it.

Key properties:

* **Every runtime is pinned, never fetched "latest".** Each `sfx-<YYYYMMDD>.<run>`
  release is immutable, and `shell-package.json` pins it by SHA-256 computed from
  the downloaded bytes. A mismatch, or an architecture with no pin, is fatal. A
  `.sha256` published beside the download is never consulted. How to move a pin:
  [`03-sfx-maintenance.md`](03-sfx-maintenance.md).
* **Nothing degrades silently.** There is no fallback to a stock runtime or a
  combined binary: a missing download or a bad hash stops the build.
* **The PHAR is built by shell-packager**, from `shell-package.json`'s `phar`
  section, with `vendor/` from a `composer install --no-dev --no-scripts` staging
  copy, so no dev dependency ships.
* **The patch is the only fork content that matters.** The SFX is otherwise a
  stock static-php-cli build (PHP 8.4 and the extension list in `build-sfx.yml`;
  the list was first copied from Boson's *standard* macOS edition and is now
  Grafida's own). The Linux SFX links glibc dynamically (see 03); macOS and
  Windows are fully static.

## Why a fork — and why *this* fork

* **Why fork phpmicro at all?** The fix must live in the stub's C code (where
  the payload is located at runtime). No packaging trick can work: signing
  requires no trailing data, stock phpmicro requires trailing data — verified
  empirically (see 01). Upstream had no external-payload mode.
* **Why fork `static-php/phpmicro` and not `dixyes/phpmicro`?** Because
  static-php-cli (the only practical way to build micro) pins its `php-micro`
  source to `static-php/phpmicro`. Forking what actually gets built avoids any
  drift between "the code we patched" and "the code SPC compiles".
* **Why does the CI live on the phpmicro fork and not in Grafida?** The SFX is
  a pure function of (phpmicro source, PHP version, extension list) — nothing
  Grafida-specific. Building it where the source lives keeps Grafida a pure
  consumer: it only ever *downloads* a pinned release. Note phpmicro cannot build
  itself (it is a PHP SAPI); the workflow checks out static-php-cli as a tool and
  points it back at the repo with `-L "php-micro:$GITHUB_WORKSPACE"`.
* **Why is signing NOT done in that CI?** Signing needs the Developer ID
  certificate/keychain, the notary profile, the Azure signing account, and the
  app payload — all of which live on the release build machine. The fork only
  produces the unsigned, app-agnostic runtime; shell-packager replaces its ad-hoc
  signature anyway.
* **Why not upstream the patch?** Deliberately deferred until battle-tested.
  When the time comes: the patch is `php_micro_fileinfo.c` +
  `php_micro_hooks.c` (the `MICRO_TRACE_OPEN` aid) on `grafida/phpmicro`;
  candidates are a PR to `static-php/phpmicro` (and/or `dixyes/phpmicro`). If
  upstream ever ships an equivalent, the fork and its CI can be retired and
  `shell-package.json` pointed at upstream builds.

## Non-obvious design decisions (learned the hard way)

1. **The sibling path is `realpath()`-canonicalised in the C patch.** phpmicro's
   offset stream hooks match the payload file by *exact path string*; PHP
   normalises paths internally. The `..` in the `../Resources` candidate made
   *some* phar reads miss the offset hooks → phantom `zlib: data error` /
   "internal corruption of phar" failures that cost hours. Never construct the
   payload path with `..` left in. On Windows the fork resolves the path through
   the file's final path (`GetFinalPathNameByHandleW`), because an 8.3 short name
   in the path made PHP's `realpath()` and the stub disagree (see 03).
2. **Data files must live in `Contents/Resources`.** `codesign` refuses any
   non-code file inside `Contents/MacOS` ("code object is not signed at all").
   So `Contents/MacOS` holds only the shell and the SFX stub; the PHAR,
   `shell.json` and the icon are in `Contents/Resources`. The shell build has no
   `assets/` directory and no dylib: the worker serves the SPA from its PHAR.
3. **The `.phar` file is not a bare phar.** It starts with phpmicro's extra-INI
   block, `[\xfd\xf6\x69\xe6][4-byte big-endian len][ini]`, which shell-packager
   writes from `shell-package.json`'s `phar.ini` (`memory_limit`, `opcache`, …).
   The patched stub parses it in sibling mode exactly as in appended mode, before
   any PHP runs, so it can set what `ini_set()` cannot. phpmicro silently ignores
   a malformed block, which is why shell-packager's worker check reads every
   setting back (an ini probe) before it writes a package.
4. **static-php-cli's micro source is named `php-micro`**, not `micro`. A
   `-G "micro:…"`/`-L "micro:…"` override is **silently ignored** and you build
   stock phpmicro. Always verify the patch is present in what you built — the
   CI's sibling-mode smoke tests exist precisely for this.
5. **The Windows SFX is a GUI-subsystem PE**, so the worker opens no console.
   static-php-cli's patch makes micro count as `cli`, which dropped
   `CREATE_NO_WINDOW` for the worker's own `proc_open`/`exec` children; the fork's
   Windows build restores it (see 03).
6. **Debug aid:** any patched binary logs every plain-file open and whether the
   payload-offset hooks engaged when run with `MICRO_TRACE_OPEN=1`.

## Platform status

| Platform | Status |
| --- | --- |
| macOS arm64 | **Signed + notarised, verified end-to-end** (gh-88): the SFX stub in `Contents/MacOS`, `grafida-worker.phar` in `Contents/Resources`, from the immutable `sfx-20261109.15`, signed by shell-packager; `spctl`: "Notarized Developer ID" for the image and the app in it |
| macOS x86_64 | Built from the immutable `sfx-20261111.28` (a promotion of `sfx-latest`'s bytes, checked natively on `macos-15-intel`) and shell 0.4.1. Grafida's Intel bundle is tested only under Rosetta 2; there is no Intel hardware or guest |
| Windows x64 | **Authenticode via Jsign/Azure Trusted Signing**, four signatures a build (shell, worker stub, uninstaller, installer), each refused if the PE carries an overlay. `grafida-worker.exe` is the **GUI** SFX from the immutable `sfx-20261110.27`, beside `grafida-worker.phar`. Verified (gh-91) signed with a test certificate, installed, run and uninstalled in the Windows 11 guest |
| Linux | No OS-enforced signature gate. The tarball (gh-86) ships the same split layout anyway: `grafida-worker` (the glibc SFX from the immutable `sfx-20261109.16`) + sibling `grafida-worker.phar` (extra-INI block + PHAR). Sibling mode and the ini block are smoke-tested on Linux by the fork's CI (`check-linux-sfx.sh`) |

---

## Boson, up to 0.3 (historical)

> **Historical.** Up to 0.3 Grafida shipped a Boson-compiled app: `boson compile`
> produced one combined binary (the SFX stub with the app PHAR appended), which
> the packaging scripts split into a stub and a sibling PHAR for signing. 0.4
> deleted that build (grafida/grafida#92). `boson.json`,
> `build/tasks/compile-target.php`, `pe-sfxsize.php`, `scripts/fetch-sfx.sh`,
> `make-macos-app.sh`, `make-dmg.sh`, Boson's `make-windows-installer.sh`,
> `sign-windows-exe.sh`, `build/windows-installer.nsi`, the Phing `git-*`, `pack`
> and `prepare-sfx` targets and `build/sfx/` no longer exist. Kept for one
> release, then archived.

### How the Boson build used the fork

The macOS packaging script *split* the Boson-compiled binary: the clean, signable
stub became `Contents/MacOS/grafida`, the payload became
`Contents/Resources/grafida.phar`. The Windows script did the same to
`grafida.exe`. Appended-payload mode was untouched, so the same patched runtime
worked identically on Linux and in unsigned dev builds.

```
grafida/phpmicro (branch sibling-phar)          ← the C patch lives here
  └─ .github/workflows/build-sfx.yml            ← CI: builds micro.sfx per platform
       │  (stock static-php-cli, pointed at the repo itself via -L)
       ▼
  GitHub release "sfx-latest" (rolling)         ← macos-{aarch64,x86_64}.standard.sfx
       │                                           + windows-x86_64.standard.sfx + .sha256
       ▼  scripts/fetch-sfx.sh                  ← download + SHA-256 verify (auto via
       │                                           Phing prepare-sfx / build-all.sh)
       ▼
  build/sfx/<os>-<cpu>.standard.sfx             ← gitignored, per-machine
       │
       ▼  build/tasks/compile-target.php        ← injects target `sfx` key when present;
       │                                           pre-cleans output dirs; --all for build-all.sh
       ▼
  boson compile                                 ← stub = OUR runtime, payload appended as usual
       │
       ├─▼  scripts/make-macos-app.sh           ← detects patched runtime, SPLITS stub/payload,
       │  │                                        Resources layout, signs (ad-hoc or Dev-ID)
       │  ▼  scripts/make-dmg.sh                ← DMG, notarytool submit, staple
       │
       └─▼  scripts/make-windows-installer.sh   ← detects patched runtime, SPLITS grafida.exe into
          │                                        a clean stub + sibling grafida.phar (offset from
          │                                        build/tasks/pe-sfxsize.php), signs ONLY the stub
          │                                        (Authenticode via Jsign — sign-windows-exe.sh),
          ▼                                        NSIS bundles both (HAVE_PHAR)
       Grafida-<v>-windows-amd64-Setup.exe      ← installer PE also signed (no PHAR overlay)
```

The Windows split was the exact analogue of the macOS one. `pe-sfxsize.php`
computed the split offset the same way phpmicro's `_micro_init_sfxsize()` does at
run time — `max(PointerToRawData + SizeOfRawData)` over the PE sections — and
asserted Boson's extra-ini magic (`fd f6 69 e6`) sat there before splitting, so a
wrong boundary could never ship. `sign-windows-exe.sh` additionally **refused**
any PE that still carried a PHAR overlay (it ran `pe-sfxsize.php` as a tripwire),
so the combined binary could never be signed by accident. This is why the split
stub was signed *before* packaging but the phar was a plain sibling.

Key properties of the Boson build:

* **Everything degraded gracefully.** No `build/sfx/` (offline, fresh machine,
  fetch failure) → stock Boson runtime, legacy combined binary, ad-hoc
  signature; Developer-ID signing aborted with an explanatory error instead of a
  cryptic codesign failure.
* **The committed `boson.json` was untouched.** The `sfx` key was injected into
  the throwaway per-build config only when the file actually existed (Boson
  errors out on a dangling `sfx` path).
* **The SFX mirrored Boson's runtime**: PHP 8.4 and Boson's *standard* macOS
  extension list, enumerated in `vendor/boson-php/compiler/src/Target/MacOSBuiltinTarget.php`.
* **Why NOT fork `boson-php/backend-src`?** It was considered (it is Boson's
  own SPC fork, from whose releases Boson downloads its stock SFX binaries).
  Unnecessary: a stock crazywhalecc/static-php-cli build of the patched micro
  works — the notarised app runs — so there was nothing in Boson's SPC fork we
  needed.

Boson-only design decisions:

1. **Boson's generated entrypoint mounted `assets/public` and the libboson dylib
   relative to the phar**, whose `__DIR__` was Resources — so `assets/` were
   copied to Resources and the dylib (a *real*, signed file in MacOS) got a
   **symlink** in Resources.
2. **The split payload kept Boson's extra-INI block** (the boson.json `ini`
   settings) in front of the PHAR — the split file was never "cleaned" down to
   the phar bytes.
3. **Boson's compile cleanup choked on the previous `Grafida.app`** (it cannot
   delete the symlink-containing bundle) and aborted — historically *silently
   leaving a stale binary* that the packaging then signed. `compile-target.php`
   pre-cleaned every target output dir, and `build-all.sh` compiled through
   `compile-target.php --all` for the same reason. The check was:
   `head -c $(stat -f%z build/sfx/<sfx>) <binary> | md5` must equal
   `md5 build/sfx/<sfx>`.

Boson-era platform status: macOS arm64 signed + notarised and verified
end-to-end (CI-built SFX from `sfx-latest`, split bundle, `spctl`: "Notarized
Developer ID"); macOS x86_64 SFX built by CI, sibling mode verified under
Rosetta, full sign/notarise pass never exercised on a real build; Windows
Authenticode-signed via the split (signing the *combined* binary had failed at
startup on `Phar::mapPhar`, "grafida.exe has a broken signature"); Linux stock.
