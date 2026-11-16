# SPA entry point and branding assets (Layout notes)

Read before touching `assets/private/` layout or `assets/logo/`.

- `assets/private/` — SPA (`view/index.html`, `css/`, `js/`, `js/tinymce/`).
  ⚠️ **The front-end notes live in `.claude/rules/spa-frontend.md`**, which loads automatically
  whenever you work under `assets/private/`. Everything about the TinyMCE 8 wiring (including the
  mandatory `license_key: 'gpl'`, without which the editor silently starts **read-only**), the
  npm-vendored and **gitignored** front-end libraries, CodeMirror source editing, the
  slash-command menu, spell checking, editor colour-scheme resolution, the Styles drop-down, the
  media browser and the collapsible layout is in there. Read it before changing anything in the
  SPA. It is deliberately **not** at `assets/private/AGENTS.md`, because the worker PHAR carries
  `assets/private` wholesale (`shell-package.json`'s `phar.include`) into every package.

- `assets/logo/` — application icon and branding artwork. `grafida.svg` is the **single master**
  (clipart pencil drawing a capital “J”); `scripts/make-icons.sh` rasterises it into `Grafida.icns`
  (macOS), `Grafida.ico` (Windows), a `png/` set + `grafida.png` (Linux), all committed. It also
  holds the DMG background artwork (`dmg-background.svg` master + its rendered `.png`/`@2x.png`/
  `.tiff`, via `scripts/make-dmg-background.sh`) and the Linux desktop entry (`grafida.desktop`).
  Wiring, all through `shell-package.json`: shell-packager puts the `.icns` into the bundle +
  `Info.plist` (`CFBundleIconFile`), writes the `.ico` into the Windows executables and ships it
  beside `Grafida.exe`, and ships `grafida.desktop` (renamed `app.grafida.desktop`) + the hicolor
  PNG set in the Linux tarball; the DMG background is `macos.dmg.background`. It lives under `assets/` (not `build/`, which is gitignored
  apart from a small whitelist) because these are versioned source assets, not build output.
  Re-run make-icons after editing the SVG.
