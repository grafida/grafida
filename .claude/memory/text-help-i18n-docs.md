# AI text normalising, in-app Help, Markdown, i18n, language files and docs (Layout notes)

Read before touching `src/Text/`, `src/Help/`, `src/Markdown/`, `src/I18n/`, `language/`, `docs/`, `src/sql/` or the glossaries.

- `src/Text/` — **normalising AI-generated content**: `ContentNormaliser` strips the invisible
  characters LLMs and web pages leave in text (zero-width family, bidi controls and isolates, tag
  characters, variation selectors, soft hyphen, the `\p{Cf}` catch-all) and, in the default mode,
  collapses exotic spaces onto U+0020. Character tables follow "Layer A" of
  guillaumemeyer/watermarks-remover; **homoglyph folding is deliberately not implemented** (a Greek
  surname is not a watermark). `ContentNormalisationService` is the setting behind it — `full`
  (default) / `invisible` / `off`, generic `settings` key `content_normalisation`, **no migration**,
  sent as `bootstrap`'s `contentNormalisation`, written via `POST /api/settings/content-normalisation`;
  `AVAILABLE` is mirrored by `CONTENT_NORMALISATION_CHOICES` in `app.js`. It is three-way rather than
  a toggle because the space half is not as safe as the invisible half: a no-break space is French
  punctuation and U+3000 is ordinary Japanese.
  ⚠️ **Some invisible characters are load-bearing and the decision is contextual**: ZWJ/VS16 after an
  emoji base build 👨‍👩‍👧 and ❤️‍🔥, tag characters after an emoji base spell a subdivision flag, ZWNJ/ZWJ
  after a letter are Persian/Arabic/Indic orthography, and U+0600–0605 & co. are Arabic number signs.
  A joiner is judged against **the last character kept**, never the last character *seen* — a stripped
  character must not lend its meaning to the next one.
  ⚠️ **There is exactly one implementation and it is in PHP.** It is applied where text crosses a
  boundary — `AiRenderer::render()` (AI replies, and so Insert), `SettingsController::convertMarkdown()`
  (imported Markdown), `SettingsController::clipboardText()` (paste as plain text) and
  `PublishService::publish()` (title/body/metadata, the guarantee point). Do not mirror the tables into
  JavaScript to catch an ordinary Cmd+V: the publish sweep already covers it, and two tables would
  drift. It works on **code points**, so an entity spelling (`&#8203;`) survives, and it is applied to
  HTML as a flat string — none of the characters it touches can occur in HTML syntax, and a string
  pass cannot reformat the markup the way a parse/serialise round trip would.
- `src/Help/HelpService.php` — the **in-app documentation** (gh-55). `docs/` is a **single source
  with two consumers**: the sidebar's **Help** screen and the Grafida.app Documentation section,
  published by `scripts/upload-docs.sh` (`composer docs:publish`), which uploads the pages,
  `docs/images/` and `_manifest.json` over SFTP — the manifest last, so a reader loading the site
  mid-upload never sees a table of contents pointing at a page that has not arrived. It is
  deliberately **not** a release step and **not** a Phing target: the app serves Help out of its own
  binary, so the site and the shipped build are independent and a typo fix should not wait for a
  version bump. It also **never deletes**, so a renamed or dropped page keeps its old file on the
  server until somebody removes it by hand. The shared source is one flat directory of `.md` files whose names are the page
  slugs, with no YAML front matter and inter-page links written as bare relative page names. The table
  of contents is `docs/_manifest.json`, a **tree** (`{slug?, title, children?}`, max depth 4; a
  node with no `slug` is a heading). It is not a convenience: `glob()` does not work on `phar://`,
  so a manifest-driven index is what lets `Resources::docsDir()` read the docs straight out of the
  compiled binary with no extraction step. Endpoints `GET /api/help`, `/api/help/page/{key}`,
  `/api/help/image/{file}`; none of them touches a site, the network or the database, so the Help
  screen works with nothing configured at all.
  ⚠️ **Detail is in `.claude/rules/documentation.md`** (and the procedure in
  `assets/docs/documentation-publishing.md`), which loads when you touch `docs/`, `src/Help/` or
  `scripts/upload-docs.sh`. Two rules worth carrying without it: **no link in a
  documentation page may be followed normally** — the app's webview opens no new window and a
  same-window navigation would replace the SPA with no way back, so an external URL leaves through
  `api.openUrl()` and anything unclassified has its click swallowed (`mailto:` is deliberately
  *not* tagged external, because `UrlOpener` accepts http(s) only and would answer with an error
  toast); and **the manifest nests but the files do not** — the site builder expects a flat page namespace, so
  the hierarchy lives in the manifest and never as subdirectories.
- `src/Markdown/`, `src/I18n/` — Markdown import; language service. `I18n\UiStrings::KEYS` is the
  canonical list of UI string keys shipped to the SPA (used by `BootstrapController` and
  `SettingsController`) — so a key the SPA never reads (`GRAFIDA_MSG_VERSION_NOTE`, resolved
  server-side in `PublishService`) belongs in `en-GB.ini` but **not** in `KEYS`, or it is shipped
  to a front-end with no use for it. `LanguageService::translateIn($key, $tag)` translates into a
  **named** language instead of the interface one, for the few strings whose reader is not the
  person at the keyboard; an unshipped tag (including Joomla's `*` / All) falls back to
  `translate()`, i.e. interface language → en-GB → the key. It caches one catalogue per tag.

- `language/<tag>/<tag>.ini` — translations, one file per language (e.g. `language/de-DE/de-DE.ini`).
  (There is **no** Joomla `.sys.ini` or `language/grafida.xml` manifest, and the files are **not**
  named `<tag>.com_grafida.ini` — Grafida is a desktop app, not a Joomla component. `LanguageService`
  loads each catalogue with joomla/language's empty-extension "internal" naming, i.e. a bare `<tag>.ini`.)
  The shipped-language list is **not** hard-coded: `LanguageService::available()` discovers it at
  runtime by scanning `language/` for every `<tag>/<tag>.ini` and reading that file's
  `GRAFIDA_LANGUAGE_ENDONYM` key (the language's name in its own tongue) for the label; the default
  (en-GB) sorts first, the rest by endonym. So every `.ini` MUST carry `GRAFIDA_LANGUAGE_ENDONYM`,
  and adding a translation needs no code change (the list is sent to the SPA as `bootstrap`'s
  `availableLanguages` tag => endonym map).

- `docs/` — the user documentation, in Markdown. Shipped inside every package's worker PHAR (it is in
  `shell-package.json`'s `phar.include`) **and** published on Grafida.app; see `src/Help/` above.
- `src/sql/*.sql` — schema. `.plans/` — implementation step notes (gitignored).
- `language/glossaries/` — per-language translation glossaries (`<tag>.md`). Not a language: `LanguageService`
  only counts `language/<tag>/<tag>.ini`, and `shell-package.json` keeps `*.md` out of the PHAR.
- `build/` holds only build inputs: `composer/` (npm-vendoring script), `tasks/` (Phing tasks), `macos/shell-worker.entitlements`,
  `windows/shell-windows-installer.nsi`, `build.sample.properties`; `build.properties` is gitignored; deliverables go to the gitignored `release/`. Build
  documentation lives in `assets/docs/`; every scratch directory (shell-packager work trees, hand-off folders) is the
  gitignored `tmp/`.
