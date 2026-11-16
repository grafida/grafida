# Sites, reference data and fields (Layout notes)

Read before touching `src/Site/`, `src/Reference/` or `src/Field/`.

- `src/Site/` — site entity, repository, `SiteService` (token storage + connection test).
  `UnicodeAliases` is the per-site **tri-state** `auto`/`yes`/`no` behind the Sites form's "Site
  uses Unicode Aliases" (gh-61, `storage/migrations/10_sites_unicode_aliases.sql`); `normalise()`
  snaps NULL, `''` and anything unrecognised back to `auto`, which is what a pre-migration row and
  a form that omitted the field have to mean. It exists because reading Joomla's `unicodeslugs`
  needs `core.admin`, so "we could not read it" and "it is off" were the same answer — see
  `src/Reference/`.
  `FaviconService` (5s fetch) parses the site home page for `<link rel="icon">` / Apple
  touch icons, downloads the largest one (falling back to `/apple-touch-icon.png` then
  `/favicon.ico`), and caches the raw bytes in `site_favicons` (`FaviconRepository`).
  `sync()` is best-effort, run when a site is connected/updated (and on the manual metadata
  refresh); the cached icon is sent to the SPA as each site's `favicon` data: URI (in the
  `bootstrap`/sites payloads) and shown as a 64×64 rounded square on the Sites page and below
  the sidebar site dropdown. Under that sidebar favicon sits a **Visit site** button
  (`GRAFIDA_BTN_OPEN_SITE`, rendered by `renderSidebarFavicon()`) opening the site's `baseUrl` in
  the OS browser via `api.openUrl()`; like the favicon it only exists while a site is selected,
  and the collapsed icon rail hides it along with the whole `#site-selector`.
- `src/Reference/` — cached categories/tags/levels/fields + `EditorCssService` (5s fetch, rebase, cache).
  `reference_cache` is permanent server-side and stays **authoritative for rendering** — a screen
  always paints from the cache first — with three invalidation paths: the manual Refresh buttons, a
  configurable TTL driving a fire-and-forget background refresh, and an opt-in startup cache reset
  (**default off**, because an unconditional refetch at launch reads as a hang on a bad connection).
  ⚠️ **`unicodeSlugs()` answers from the site's own `UnicodeAliases` tri-state before it looks at
  anything else** (gh-61) — no request, and deliberately no cache write either, so switching back
  to `auto` re-reads the site rather than inheriting what the user asserted. Only `auto` reaches
  the API, and only for a token holding `core.admin`.
  ⚠️ **`EditorCssService::load()` is cache-first too, and nothing else ever looks.** Finding a
  template's `editor.css` costs template discovery (a styles-API call plus a home-page fetch) and
  then a walk over up to eight candidate URLs at 5s apiece — which used to be paid on *every*
  editor open, in front of the user. It now looks only when passed `$refresh`, which
  `SiteController::warmCaches()` does when a site is connected or edited, and `references(refresh:
  true)` does on a metadata refresh. **Everything the editor needs from a site is warmed there or
  it is warmed in front of someone trying to write.** A *miss* is cached as well (an empty string),
  so a template that ships no `editor.css` does not re-walk the candidates forever — but only when
  the site actually answered, since an unreachable site taught us nothing.
  ⚠️ **Detail is in `.claude/rules/joomla-api-and-references.md`**, which loads when you touch
  `src/Reference/`, `src/Joomla/`, `src/Publish/`, `src/Field/` or `src/Site/`. The rule most
  easily broken from outside: `TemplateDiscovery` needs the **template styles API**, not just a
  home-page scan — a child template that ships only an `editor.css` renders no asset URL of its
  own and is structurally invisible to any page scan (gh-3).
- `src/Field/` — `FieldSupport` (supported field-type subset + required-unsupported guard —
  `requiredUnsupported()` splits those fields into **`blocking`** (no value we could send: a dead
  end) and **`overridable`** (the draft carries the value imported from the site, so a confirmed
  "Publish anyway" can send it back), gh-59),
  `MediaFieldValue` and
  `FieldCategoryScope` (gh-56), which works out **which fields Joomla actually uses for a category** —
  assigned to it, to one of its ancestors, or to no category at all; `-1` ("Only Use In Subform")
  never. ⚠️ **The fields *list* endpoint does not carry the assignment and the API accepts no
  category filter** (`$fieldsToRenderList` omits `assigned_cat_ids`, and every API list model is
  built with `ignore_request` so `populateState()` never reads a `filter[…]`), so
  `ReferenceService::fetchFields()` pays **one item request per field** on a cache refresh to learn
  it. A field whose assignment is unknown stays in scope everywhere — hiding it would silently drop
  whatever the user typed into it. `annotate()` expands the assignment **downwards** into
  `categoryIds` (null = all) so the SPA re-scopes on a category change with an `includes()` test and
  the tree walk has exactly one implementation.
  **`media` is a supported type** — the one core field type Grafida can offer only because it has a
  media picker of its own (`openMediaBrowser()`, both tabs), so no site-side media manager is needed.
  ⚠️ **Its value is a record, not a path.** `plg_fields_media` renders the form field as
  **`accessiblemedia`**, a non-repeatable subform of `imagefile` / `alt_text` / `alt_empty`, and
  `#__fields_values.value` holds it as a JSON object string. `MediaFieldValue::decode()`/`encode()`
  is the only thing that should read or write it (mirrored in `app.js` by
  `decodeMediaFieldValue()`/`encodeMediaFieldValue()`); it understands all three shapes that reach
  us — the JSON string, an already-decoded object, and a **Joomla 3 bare path**, whose fallback
  `plg_fields_media::checkValue()` still carries. Two traps: **a write must carry the whole record**
  (`AccessiblemediaField::setup()` returns false for an object missing `imagefile` *or* `alt_text`,
  and `Form::filter()` drops a field whose setup failed — so a partial record is not a partial save
  but *no* save, and nothing errors), and an empty `imagefile` must collapse to the **empty string**,
  which is how `plg_system_fields` is told to clear the value. A picture picked offline is held as the
  same `grafida-media://N` sentinel the intro/full-text images use;
  `PublishService::resolveMediaField()` uploads it and rewrites the value the way Joomla's own media
  field does, `#joomlaImage://` fragment included — see `.claude/rules/media-and-publish.md`.
