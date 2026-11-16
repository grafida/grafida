# Grafida — AI assistant orientation

Grafida is a cross-platform **desktop application** (macOS, Windows, Linux) for creating
and editing **Joomla! articles** through the Joomla Web Services (REST) API. It is built in
**PHP 8.4** on its own native shell ([grafida/shell](https://github.com/grafida/shell)), uses **SQLite** for all local storage
(via **`joomla/database`**'s `SqliteDriver`, wired through a **`joomla/di`** container),
and **TinyMCE 8** as the HTML editor. Licensed **GNU GPL v3 or later**. Dev happens on macOS.

## Scope (what we deliberately do NOT support)

Grafida is an **offline article editor**: it composes article HTML locally and publishes it
through the REST API. It is **not** the Joomla back-end and does **not** reuse the Joomla
WYSIWYG editor environment. Consequently we do **not** support — and will not try to emulate —
**page builders** (SP Page Builder, JSN, Quix, etc.), **editor-button/editor-xtd plugins**
(e.g. the article/image/page-break/module-insert buttons, sliders, tabs, third-party
shortcode buttons), or **custom/alternative media managers**. Article bodies are plain HTML
authored in TinyMCE; images go through Grafida's own offline media picker (see `src/Media/`),
not a site-side media-manager plugin. Don't add features that depend on server-side editor
plugins or builder shortcodes.

## Layout at a glance

`src/` (PHP back-end, one folder per area) · `assets/private/` (SPA) · `bin/worker.php` (the only entry point) ·
`shell.json` + `shell-package.json` (shell manifest / packager config) · `language/<tag>/<tag>.ini` ·
`storage/migrations/*.sql` · `docs/` (user docs, shipped in-app and published) · `build/`, `scripts/` · `tests/`.
Request flow: shell → frame → `bin/worker.php` → `FrontController` → `Kernel` (pure `Request → Response`) → `ApiController` / static files.

## Commands

- `composer test` — the whole suite (PHPUnit unit/integration/feature/pipe + `test:js`); `composer linter:check` — PHPStan (level max + strict rules).
- `composer shell:dev` (also `composer start`, `phing run`) — dev mode; `composer build` — package every platform into `build/dist/`.

## Always-on rules

- Every PHP file starts with the GPLv3 copyright docblock and `declare(strict_types=1)`.
- **Never rename the page origin `shell://app`** (stored in drafts and `.grafida` exports). stdout of `bin/worker.php` carries the frame stream; never print to it.
- **Never `new` a service — register it in a provider** (`src/Application/Provider/`). No global DB object. An endpoint = a handler method + one line in that controller's `registerRoutes()`.
- Data access: `Joomla\Database\DatabaseInterface`, query-builder-first; `bind()` takes its value by reference (bind from a variable); never `insert()->set()` (use `columns()`/`values()`); `loadAssoc()`/`loadResult()` return `null` on no rows.
- Files are picked in the shell's own dialogs, never through `<input type="file">`; no path ever comes from JS.
- **Joomla writes:** a flat top-level JSON body (no `{data:{…}}` envelope), and send `introtext`/`fulltext`, **never** `articletext` (a PATCH silently reverts the body).
- **AI assistant:** the streaming provider call stays in the SPA's JS (do not move the API key/call back to PHP); text only, no AI images; AI traffic must never reach the Request Log.
- No release shell carries the automation channel, and no build opens a remote-debugging port. Run dev mode with `HOME`/`XDG_*` pointed at a throwaway directory.
- Signing on macOS and Windows depends on the phpmicro `sibling-phar` SFX stub and its PHAR being **two files**; never sign a combined binary.
- The **Feature suite is the API contract**: do not edit an assertion to make a refactor pass. `composer phpcs:check` fails on every `src/` file at pristine `HEAD`; it is not a gate.
- UI strings: add to `language/en-GB/en-GB.ini` and `I18n\UiStrings::KEYS`, then translate (see translation flow). Never build a localised sentence by concatenating fragments; use `%s` placeholders with `formatNodes()`/`formatText()`.
- UI icons are FontAwesome 7 Free solid (`icon()`/`iconBtn()`), with a leading `aria-hidden` icon on action buttons.
- **Commit directly on `main`. Do NOT create or switch to feature branches unless explicitly asked.** (Solo project; overrides the "branch first" default.)
- **Every change adds its CHANGELOG entry**, terse, placed in its significance group (`!` `+` `-` `~` `# [HIGH|MEDIUM|LOW]`); the CHANGELOG is the single source of truth for the version.
- **Keep the checked-in documentation current in the same change:** this `AGENTS.md`, the path-scoped `.claude/rules/*.md` and the matching `.claude/memory/*.md`. A new rules file's `paths:` must actually cover the code it describes.
- Do not put a nested agent-instructions file at `assets/private/`: the worker PHAR carries it wholesale into every package.

## Project memory

Project memory lives in `.claude/memory/`. Read the matching file before starting that kind of work. The
`.claude/rules/` files auto-load in Claude Code when you touch their `paths:`; other harnesses should read them as listed.

| File | Read it when… |
|------|---------------|
| [`native-shell.md`](.claude/memory/native-shell.md) | Before you touch `shell.json`, `bin/worker.php`, the shell/worker protocol, file dialogs or per-platform package layouts |
| [`backend-architecture.md`](.claude/memory/backend-architecture.md) | Before you touch `src/Application/`, `src/Http/`, `src/Joomla/`, `src/Secret/`, `src/Debug/`, `src/Storage/`, `src/Support/` or `src/Startup/` |
| [`sites-reference-and-fields.md`](.claude/memory/sites-reference-and-fields.md) | Before you touch `src/Site/`, `src/Reference/` or `src/Field/` |
| [`drafts-media-publish-html.md`](.claude/memory/drafts-media-publish-html.md) | Before you touch `src/Article/`, `src/Media/`, `src/Publish/` or `src/Html/` |
| [`settings-and-services.md`](.claude/memory/settings-and-services.md) | Before you touch `src/Update/`, `src/Display/`, `src/Clipboard/` or `src/Editor/` |
| [`text-help-i18n-docs.md`](.claude/memory/text-help-i18n-docs.md) | Before you touch `src/Text/`, `src/Help/`, `src/Markdown/`, `src/I18n/`, `language/`, `docs/`, `storage/migrations/` or glossaries |
| [`ai-assistant-backend.md`](.claude/memory/ai-assistant-backend.md) | Before you touch `src/Ai/`, the AI controllers or `assets/private/js/ai/` |
| [`spa-and-branding-assets.md`](.claude/memory/spa-and-branding-assets.md) | Before you touch the `assets/private/` layout or `assets/logo/` |
| [`joomla-api-key-facts.md`](.claude/memory/joomla-api-key-facts.md) | Before you build or change any request to the Joomla REST API |
| [`build-overview.md`](.claude/memory/build-overview.md) | Before you run `composer build` or Phing targets |
| [`release-signing-notes.md`](.claude/memory/release-signing-notes.md) | Before you verify a Windows signature on macOS or cut a release |
| [`conventions.md`](.claude/memory/conventions.md) | Before you write PHP, tests, database code, UI strings or documentation (full conventions) |
| [`translation-flow.md`](.claude/memory/translation-flow.md) | Before you add or change UI strings or any `language/` file |
| [`.claude/rules/spa-frontend.md`](.claude/rules/spa-frontend.md) | Before you touch `assets/private/**` |
| [`.claude/rules/ai-assistant.md`](.claude/rules/ai-assistant.md) | Before you touch `src/Ai/**`, `assets/private/js/ai/**` or the AI controllers |
| [`.claude/rules/media-and-publish.md`](.claude/rules/media-and-publish.md) | Before you touch `src/Media/**`, `src/Publish/**`, `src/Html/**` or `tests/corpus/**` |
| [`.claude/rules/drafts-and-articles.md`](.claude/rules/drafts-and-articles.md) | Before you touch `src/Article/**` or the draft/article controllers |
| [`.claude/rules/joomla-api-and-references.md`](.claude/rules/joomla-api-and-references.md) | Before you touch `src/Reference/**`, `src/Joomla/**`, `src/Publish/**`, `src/Field/**` or `src/Site/**` |
| [`.claude/rules/internal-api.md`](.claude/rules/internal-api.md) | Before you touch `src/Http/**`, `src/Application/**` or `src/Debug/**` |
| [`.claude/rules/documentation.md`](.claude/rules/documentation.md) | Before you touch `docs/**`, `src/Help/**` or `scripts/upload-docs.sh` |
| [`.claude/rules/build-and-packaging.md`](.claude/rules/build-and-packaging.md) | Before you touch `build/**`, `scripts/**`, `build.xml`, `shell-package.json`, `composer.json`, the CHANGELOG or release notes |
