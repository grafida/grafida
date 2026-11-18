-- Adds the article's "Created by Alias" (Joomla's `created_by_alias`) to drafts.
--
-- Copyright (c) 2026 Nicholas K. Dionysopoulos
-- GNU General Public License version 3, or later.

ALTER TABLE drafts ADD COLUMN created_by_alias TEXT NOT NULL DEFAULT '';
