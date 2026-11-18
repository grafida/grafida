# Conventions (full text)

Read before writing PHP, tests, UI strings or documentation.

- Every PHP file starts with the GPLv3 copyright docblock. `declare(strict_types=1)`.
- `composer test` runs the suite; `composer linter:check` runs PHPStan (level max + strict rules).
- Add new UI strings to `language/en-GB/en-GB.ini` (canonical) and the `KEYS`
  list in `Grafida\I18n\UiStrings`, then translate. **See the translation flow below.**
- **Never `new` a service — register it in a provider** (`src/Application/Provider/`) and let the
  container inject it. There is no global database object and no singleton to reach for.
- **Adding an endpoint** = a handler method on the right `src/Http/Controller/` class + one line in
  that controller's `registerRoutes()`. Nothing else changes — no constructor chain to thread.
- **Data access goes through `Joomla\Database\DatabaseInterface`, query-builder-first**
  (`$db->createQuery()->select(…)->from($db->quoteName(…))->where(… . ' = :id')->bind(':id', $id, ParameterType::INTEGER)`).
  Drop to raw `setQuery('…')` + `bind()` only where the builder has no vocabulary: the `ON CONFLICT`
  upserts, `PRAGMA`s, and the `sqlite_master` introspection query. Three traps, all of which have
  already bitten this codebase:
  - **`bind()` takes its value BY REFERENCE.** Bind from a variable (or an array *element*), never
    from an expression or literal — and the variable must still hold the right value at
    `execute()` time, not just at `bind()` time.
  - **`$query->insert()->set()` emits MySQL-only syntax** (`INSERT INTO t SET a = …`), which SQLite
    rejects. Always use `insert()->columns([…])->values('…')`.
  - **`loadAssoc()`/`loadResult()` return `null`** on no rows, not PDO's `false`.
  Upserts use SQLite's `ON CONFLICT(key) DO UPDATE SET col = excluded.col` so no placeholder is ever
  bound twice (native prepares reject a re-used named parameter with "column index out of range").
- Tests build the app from the container: `tests/Support/TestContainer::create()` gives a fully-wired
  app on an in-memory, already-migrated database (it takes the `secret.store` tri-state and an
  optional dialog stub); `TestDatabase::memory()` gives a bare `DatabaseInterface` for repository
  unit tests. The **Feature suite is the API contract** — it drives `Kernel::handle()` over every
  route and asserts status + JSON shape. Do not edit an assertion to make a refactor pass.
- ⚠️ `composer phpcs:check` currently fails on **every** `src/` file, including at pristine `HEAD` —
  the installed php-cs-fixer disagrees with the committed formatting. It is **not** a usable gate;
  match the surrounding style by eye and rely on `linter:check` + `test`.
- Never build a localised sentence by concatenating fragments around an injected value — word
  order differs per language. Keep each message a single string with `%s` placeholders and
  interpolate in the SPA with `formatNodes(t('KEY'), node)` (returns text/DOM nodes to spread
  into `el()`), mirroring Joomla's `Text::sprintf()`. `formatText()` is the plain-string
  counterpart (toasts); **`formatNodesStrong()` emphasises the template's own literal text**
  instead of the substitution, which is how a `<strong>Created:</strong> 20 Jul 2026` pair is
  rendered from one string rather than from a label key plus a value key — the label, its
  punctuation and its position relative to the value all stay the translator's to decide. All
  three share `interpolateNodes()`.
- **Commit directly on `main`. Do NOT create or switch to feature branches unless explicitly
  asked.** This project is worked solo and the branch overhead is unwanted; this deliberately
  overrides the general "branch first if on the default branch" default. If a branch was created
  by default tooling behaviour, fast-forward merge it into `main` and check `main` back out.
- **Every change adds its CHANGELOG entry**, terse and placed in its significance group
  (`!` `+` `-` `~` `# [HIGH|MEDIUM|LOW]`) rather than appended — the format is documented under
  "CHANGELOG style" in `.claude/rules/build-and-packaging.md`. The CHANGELOG is also the single
  source of truth for the version number.
- **Keep the checked-in documentation current in the same change.** That is *two* things: this
  root `AGENTS.md` and the path-scoped files under `.claude/rules/` (see the Project memory table in `AGENTS.md` for the map), plus the matching `.claude/memory/` file. The rules
  files are the easier ones to forget precisely because they are not always in context — but a
  change to the SPA, the AI assistant, media/publishing, drafts, the reference cache, the internal
  API or the build system is almost always made in a session where the matching rules file *was*
  loaded, so update it there and then. If nothing documented changed, leave it alone.

### Where the rest of the documentation lives

`AGENTS.md` is always in context; each `.claude/rules/*.md` file loads automatically (in Claude Code) only
when you touch the paths it declares in its `paths:` frontmatter, and other harnesses find them through the
Project memory table in `AGENTS.md`. Each root pointer keeps its area's safety-critical prohibition resident,
so those never depend on a rules file being loaded. A change that moves content *between* these files must
keep the root file's pointers accurate.

⚠️ A new rules file's `paths:` must actually cover the code it describes, or it will silently
never load. Deep build/signing recipes live in `assets/docs/macos-signing.md`, `signing-architecture.md`, `sfx-maintenance.md` and `windows-signing-on-macos.md`, and publishing the
documentation to Grafida.app in `assets/docs/documentation-publishing.md`.

