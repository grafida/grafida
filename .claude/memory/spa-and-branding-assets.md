# SPA entry point and branding assets (Layout notes)

Read before touching `media/private/` layout or `build/logo/`.

- `media/private/` — SPA (`view/index.html`, `css/`, `js/`, `js/tinymce/`).
  ⚠️ **The front-end notes live in `.claude/rules/spa-frontend.md`**, which loads automatically
  whenever you work under `media/private/`. Everything about the TinyMCE 8 wiring (including the
  mandatory `license_key: 'gpl'`, without which the editor silently starts **read-only**), the
  npm-vendored and **gitignored** front-end libraries, CodeMirror source editing, the
  slash-command menu, spell checking, editor colour-scheme resolution, the Styles drop-down, the
  media browser and the collapsible layout is in there. Read it before changing anything in the
  SPA. It is deliberately **not** at `media/private/AGENTS.md`, because the worker PHAR carries
  `media/private` wholesale (`shell-package.json`'s `phar.include`) into every package.

- `build/logo/` — application icon and branding artwork. `grafida.svg` is the **single master**
  (clipart pencil drawing a capital “J”); `build/scripts/make-icons.sh` rasterises it into `Grafida.icns`
  (macOS), `Grafida.ico` (Windows), a `png/` set + `grafida.png` (Linux), all committed. It also
  holds the DMG background artwork (`dmg-background.svg` master + its rendered `.png`/`@2x.png`/
  `.tiff`, via `build/scripts/make-dmg-background.sh`) and the Linux desktop entry (`grafida.desktop`).
  Wiring, all through `shell-package.json`: shell-packager puts the `.icns` into the bundle +
  `Info.plist` (`CFBundleIconFile`), writes the `.ico` into the Windows executables and ships it
  beside `Grafida.exe`, and ships `grafida.desktop` (renamed `app.grafida.desktop`) + the hicolor
  PNG set in the Linux tarball; the DMG background is `macos.dmg.background`. It lives under `build/` (build inputs, committed — only
  `build/build.properties` is gitignored); `build/docs/` holds the developer docs and `build/http/` the HTTP-client request files.
  Re-run make-icons after editing the SVG.
