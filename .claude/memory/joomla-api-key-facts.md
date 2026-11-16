# Key Joomla API facts (verified against Joomla 5.4 source)

Read before building or changing any request to the Joomla REST API. Full contracts: `.claude/rules/joomla-api-and-references.md`.

## Key Joomla API facts (verified against Joomla 5.4 source)

⚠️ **These live in `.claude/rules/joomla-api-and-references.md`**, alongside the reference-cache
notes they govern; it loads when you touch `src/Joomla/`, `src/Reference/`, `src/Publish/`,
`src/Field/` or `src/Site/`.

The two that cause silent, hard-to-diagnose damage, so they stay here: **write bodies are a flat
top-level JSON object** — Joomla's `{data:{type,attributes}}` JSON:API envelope is for *responses
only*, and wrapping a write makes Joomla bind nothing and return the unchanged resource. And send
the body as the discrete **`introtext` / `fulltext`** columns, **never** the combined `articletext`
field: on a PATCH the controller backfills every column you omit from the existing record, then
`Content::bind()` overwrites what it derived from `articletext` with those backfilled OLD values —
so a PATCH sending only `articletext` **silently reverts the body**. A create has no backfill,
which is why it appears to work.

Also remember a token-bearing user is **not necessarily a Super User**: admin-only routes may 403,
so callers must degrade rather than throw.
