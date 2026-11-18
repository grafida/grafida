-- Grafida schema update: cached site favicons.
--
-- Copyright (c) 2026 Nicholas K. Dionysopoulos
-- GNU General Public License version 3, or later.

-- One downloaded favicon per site, stored as raw bytes and served back to the
-- SPA as a data: URI. Refreshed best-effort whenever a site is connected or
-- updated; an unreachable site simply keeps (or lacks) its cached icon.
CREATE TABLE IF NOT EXISTS site_favicons (
    site_id    INTEGER PRIMARY KEY REFERENCES sites(id) ON DELETE CASCADE,
    mime       TEXT    NOT NULL,
    data       BLOB    NOT NULL,
    fetched_at TEXT    NOT NULL
);
