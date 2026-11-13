-- Grafida schema update: the page origin is `shell://app`, no longer `boson://app`.
--
-- Copyright (c) 2026 Nicholas K. Dionysopoulos
-- GNU General Public License version 3, or later

-- Up to Grafida 0.3 (Boson) a local media blob was referenced from a draft's body as
-- `boson://app/api/media/{id}/raw?rev=…`. The native shell serves `shell://app` instead, and a webview
-- cannot load a scheme nobody registered, so every such <img> would paint broken. Rewrite them in place.
-- Only the exact prefix is touched; the rest of the URL, the blob ids and the ?rev= tokens are unchanged.
-- Exactly-once, like every migration here (tracked by file name in `schema_migrations`).
UPDATE drafts
   SET html        = REPLACE(html, 'boson://app/api/media/', 'shell://app/api/media/'),
       images_json = REPLACE(images_json, 'boson://app/api/media/', 'shell://app/api/media/'),
       fields_json = REPLACE(fields_json, 'boson://app/api/media/', 'shell://app/api/media/')
 WHERE html LIKE '%boson://app/api/media/%'
    OR images_json LIKE '%boson://app/api/media/%'
    OR fields_json LIKE '%boson://app/api/media/%';
