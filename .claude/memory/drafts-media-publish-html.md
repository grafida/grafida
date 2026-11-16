# Drafts, media, publishing and HTML (Layout notes)

Read before touching `src/Article/`, `src/Media/`, `src/Publish/` or `src/Html/`.

- `src/Article/` — `Draft` entity + repository (local drafts), the alias (URL slug) preview, the
  two-tab Articles screen (Local / Remote) and the portable **`.grafida`** export format. A draft
  remembers the `site_id` + `remote_id` it mirrors; drafts are only written to the DB on the first
  Save, so opening an unchanged remote article leaves no local draft behind.
  ⚠️ **Detail is in `.claude/rules/drafts-and-articles.md`**, which loads when you touch
  `src/Article/` or the draft/article controllers. Two traps worth carrying without it: **any new
  article attribute must first be checked against com_content's `article.xml` form** — a field
  absent from it is dropped *silently* on save; and the draft timestamps are naive UTC strings
  compared **as strings**, never via `Date.parse()`, which WKWebView mishandles for that form.

- `src/Media/` — offline image blobs (`media_blobs`) + `SiteImageFetcher`, the Media Manager
  screen (Site + Local tabs), the in-app crop/resize/rotate/flip image editor, and
  `MediaUploadTarget` — **where a publish uploads to** (gh-57), i.e. the per-site
  `media_adapter`/`media_folder` settings (`storage/migrations/09_sites_media_target.sql`, Sites
  form) and the automatic resolution behind them. It also owns the two statics `PublishService`
  used to keep privately — `safeName()` (the blob-id-prefixed upload name) and `publicPath()`
  (adapter path → site-relative URL) — because `predictedPublicPath()`, which answers
  `GET /api/media/{id}/target` for the editor's image URL field (gh-72), has to produce the *same*
  path the upload will, offline and without a request. ⚠️ **That answer is a prediction and the
  endpoint's `predicted` flag is load-bearing**: an unset adapter is only resolved by asking the
  site, and the file name is ultimately com_media's to choose. An already-uploaded blob answers
  from its recorded `remote_url` with `predicted: false` instead.
- `src/Publish/PublishService.php` — the publish pipeline (media upload, tags, fields, split, POST/PATCH).
  ⚠️ **Both are detailed in `.claude/rules/media-and-publish.md`**, which loads when you touch
  `src/Media/`, `src/Publish/` or `src/Html/`. They are one pipeline and are documented together.
  Three rules worth carrying without it: a pasted image is referenced by a **local URL the kernel
  serves** (`shell://app/api/media/{id}/raw?rev=…`), **not** an inlined `data:` URI (gh-36) — the
  latter is what froze the source editor on a couple of screenshots; `rewriteOfflineImages()`
  uploads **every** offline image on publish, tagged or not, so none leaks into the published HTML;
  and the upload path **names its adapter and is relative to that adapter's root**
  (`local-images:/grafida/<file>`, **not** `images/grafida/<file>`, which writes to `images/images/…`
  and yields a broken image) — leaving the adapter out is gh-57: Joomla then resolves the path
  against com_media's `file_path` parameter, `files` on a stock install, so every published image
  landed in the site's *files* folder.
  ⚠️ **`publish()` takes a `$force` flag and it is not a "skip the checks" switch** (gh-59): it
  makes the guard *send* the imported values of the required unsupported fields rather than refuse.
  Only the **required** ones are ever sent — an omitted `com_fields` key keeps the site's own value,
  because the API never fires `onContentNormaliseRequestData`, so leaving an optional one out is
  strictly safer than overwriting it with our snapshot.
- `src/Html/` — `ContentSplitter` (read-more split), `CssRebaser`, `InlineMedia`, `HtmlDocument`.
  ⚠️ **`HtmlDocument` is PHP 8.4's `\Dom\HTMLDocument` — a real WHATWG HTML5 parser (Lexbor) — and
  never `\DOMDocument`**, whose HTML support is libxml2's HTML4 parser with a custom, unspecified
  tree-construction algorithm. The difference is not cosmetic for article HTML: HTML4 leaves
  `<section>`/`<figure>` nested inside an open `<p>`, leaves stray content between `<table>` and
  `<tr>` where it stands instead of foster-parenting it out, implies no `<tbody>`, repairs misnested
  inline tags ad hoc rather than by the adoption agency algorithm, knows only the HTML4 entity
  table, and assumes ISO-8859-1 without a `<meta charset>` — each of which publishes a *different
  article* from the one the browser (and Joomla) shows. Two traps in the port: `\Dom\Element::
  getAttribute()` answers **null** for an absent attribute where `\DOMElement` answered the empty
  string (a silent behaviour change wherever the result was compared or `preg_*`'d), and the
  fragment is deliberately parsed inside a `<div id="grafida-root">` wrapper — not as a bare
  document, which would place a leading `<style>` or `<meta>` in the implied `<head>`, where the
  body serialiser would never see it again. ⚠️ **A stray `</div>` closes that wrapper**, leaving
  everything after it a sibling of it and so invisible to both the marker search and the serialiser
  — an article silently truncated at the stray tag, which unbalanced page-builder/Word markup
  produces routinely. `load()` therefore unwraps the wrapper whenever anything escaped it (the tell
  is the body having more than the one child we gave it) and falls back to the body itself. The libxml2-era `<?xml encoding="UTF-8">` prologue and
  `LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD` flags are **gone**, not ported.
  ⚠️ **`tests/corpus/` is the round-trip contract and is language-neutral on purpose** — its format
  is documented in `.claude/rules/media-and-publish.md` and run by `ConformanceCorpusTest`. A
  failing case is a question about which behaviour is right, never a file to regenerate.
  ⚠️ **The read-more marker has two spellings and they are equals** (gh-71): Grafida's editor
  inserts `<hr class="readmore">`, but Joomla's own writes `<hr id="system-readmore">` — the only
  form `Table\Content::check()` splits on — so an article imported from a site carries whichever it
  was written with. `ContentSplitter` matches either token in either attribute; the SPA mirrors the
  set in `READMORE_SELECTOR` (styling + duplicate check), and `ArticleController::remoteArticleBody()`
  splits an incoming combined `text` on the marker before falling back to its `"\r\n \r\n"`
  heuristic. A marker we do not recognise is *invisible* in the editor and lost on publish.
