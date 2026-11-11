/**
 * Grafida — desktop Joomla! article editor
 * Copyright (c) 2026 Nicholas K. Dionysopoulos
 * GNU General Public License version 3, or later
 *
 * Resolves a stylesheet's `prefers-color-scheme` media queries against a known
 * colour scheme, in the CSS text itself (gh-38). Exposes
 * window.GrafidaCssTheme = { resolveColorScheme }.
 *
 * Why this exists: the TinyMCE content iframe loads the site's editor.css
 * (State.editorCss, see src/Reference/EditorCssService.php). A webview's
 * `prefers-color-scheme` is not to be trusted — Boson's always reported "dark"
 * on macOS, which is why Display\DisplayModeService::systemPrefersDark() probes
 * the OS directly instead of trusting the media query for the app chrome. That
 * workaround only covers the app UI; the content iframe is a different document
 * whose CSS is evaluated by the same lying webview, so a stylesheet with
 * automatic dark mode (e.g. Bootstrap 5.3 built with $color-mode-type:
 * media-query) rendered the editor content permanently dark, whatever Grafida's
 * own resolved theme was. There is no way to make the webview report the truth,
 * so the fix resolves the condition ourselves, in the CSS text, before the
 * stylesheet becomes a Blob URL (see app.js's initTinyMCE()).
 *
 * This module is a pure string -> string transform. It depends on no app.js
 * globals, which is what makes it cheaply unit-testable (tests/js/csstheme.test.mjs).
 */

'use strict';

(function (global) {
    // -------------------------------------------------------------------------
    //  Low-level scanning: strings, comments, braces
    // -------------------------------------------------------------------------

    /**
     * Given css[i] is a quote character, returns the index just past the
     * closing quote (honouring backslash escapes), or -1 if the string is
     * never closed.
     */
    function skipString(css, i) {
        const quote = css[i];
        let j = i + 1;
        const n = css.length;
        while (j < n) {
            if (css[j] === '\\') { j += 2; continue; }
            if (css[j] === quote) return j + 1;
            j++;
        }
        return -1;
    }

    /**
     * Scans forward from `start` (the position right after an at-rule's name)
     * for the prelude's terminating top-level "{", skipping over comments and
     * strings so neither can be mistaken for the brace. Returns the position
     * of that "{", or -1 if the css ends first (a truncated/malformed rule).
     */
    function findPreludeBrace(css, start) {
        const n = css.length;
        let i = start;
        while (i < n) {
            const c = css[i];
            if (c === '/' && css[i + 1] === '*') {
                const end = css.indexOf('*/', i + 2);
                if (end === -1) return -1;
                i = end + 2;
                continue;
            }
            if (c === '"' || c === '\'') {
                const j = skipString(css, i);
                if (j === -1) return -1;
                i = j;
                continue;
            }
            if (c === '{') return i;
            i++;
        }
        return -1;
    }

    /**
     * Given `openBrace` is the index of a rule's opening "{", finds the index
     * of its matching closing "}" — depth-aware (a nested rule's own braces do
     * not end the outer one) and comment/string-aware (so a `content: "{"`
     * declaration, or a brace inside a comment, cannot corrupt the count).
     * Returns -1 for an unterminated block (truncated CSS).
     */
    function findMatchingBrace(css, openBrace) {
        const n = css.length;
        let depth = 1;
        let i = openBrace + 1;
        while (i < n) {
            const c = css[i];
            if (c === '/' && css[i + 1] === '*') {
                const end = css.indexOf('*/', i + 2);
                if (end === -1) return -1;
                i = end + 2;
                continue;
            }
            if (c === '"' || c === '\'') {
                const j = skipString(css, i);
                if (j === -1) return -1;
                i = j;
                continue;
            }
            if (c === '{') { depth++; i++; continue; }
            if (c === '}') {
                depth--;
                if (depth === 0) return i;
                i++;
                continue;
            }
            i++;
        }
        return -1;
    }

    /**
     * True when css[i] starts a whole "@media" token (case-insensitive), not
     * the tail or the prefix of some longer identifier.
     */
    function isMediaTokenAt(css, i) {
        if (css.slice(i, i + 6).toLowerCase() !== '@media') return false;
        const prev = i > 0 ? css[i - 1] : '';
        const next = css[i + 6] || '';
        if (/[A-Za-z0-9_-]/.test(prev)) return false;
        if (/[A-Za-z0-9_-]/.test(next)) return false;
        return true;
    }

    // -------------------------------------------------------------------------
    //  Recognising prefers-color-scheme forms
    // -------------------------------------------------------------------------

    // A plain feature test: (prefers-color-scheme: dark) / (…:light), whitespace
    // and case insensitive.
    const RE_VALUE_FORM = /^\(\s*prefers-color-scheme\s*:\s*(dark|light)\s*\)$/i;

    // The boolean form — "the UA has a preference at all". We always have one,
    // so this is always true; it never tells us WHICH scheme is styled.
    const RE_BOOLEAN_FORM = /^\(\s*prefers-color-scheme\s*\)$/i;

    // Level 4 `not` directly wrapping the feature: (not (prefers-color-scheme: dark)).
    const RE_NOT_WRAPPED_FORM = /^\(\s*not\s*\(\s*prefers-color-scheme\s*:\s*(dark|light)\s*\)\s*\)$/i;

    // A whole query negated with the legacy `not all and (...)` spelling.
    const RE_NOT_ALL_AND_FORM = /^not\s+all\s+and\s*\(\s*prefers-color-scheme\s*:\s*(dark|light)\s*\)$/i;

    const RE_MENTIONS_SCHEME = /prefers-color-scheme/i;

    /**
     * Splits a media-query prelude into its comma-separated queries, ignoring
     * commas nested inside parentheses (e.g. inside a future `selector()` test).
     */
    function splitTopLevelByComma(str) {
        const parts = [];
        let depth = 0;
        let start = 0;
        for (let i = 0; i < str.length; i++) {
            const c = str[i];
            if (c === '(') depth++;
            else if (c === ')') depth--;
            else if (c === ',' && depth === 0) {
                parts.push(str.slice(start, i));
                start = i + 1;
            }
        }
        parts.push(str.slice(start));
        return parts;
    }

    /**
     * Splits one query into its `and`-joined terms, ignoring an "and" that
     * appears nested inside parentheses. Only a whole word "and" (bounded by
     * whitespace) at paren-depth 0 counts as a joiner.
     */
    function splitTopLevelAnd(str) {
        const parts = [];
        let depth = 0;
        let start = 0;
        let i = 0;
        while (i < str.length) {
            const c = str[i];
            if (c === '(') { depth++; i++; continue; }
            if (c === ')') { depth--; i++; continue; }
            if (depth === 0
                && str.slice(i, i + 3).toLowerCase() === 'and'
                && (i === 0 || /\s/.test(str[i - 1]))
                && (i + 3 >= str.length || /\s/.test(str[i + 3]))) {
                parts.push(str.slice(start, i));
                i += 3;
                start = i;
                continue;
            }
            i++;
        }
        parts.push(str.slice(start));
        return parts.map((p) => p.trim()).filter((p) => p.length > 0);
    }

    /**
     * True when `prelude` contains a Level 4 `or` combinator at the top level
     * (outside any parentheses). Media features inside parentheses are masked
     * out first — this is what stops the `orientation` feature (which contains
     * the substring "or") from being mistaken for the combinator.
     */
    function hasTopLevelOr(prelude) {
        let depth = 0;
        let flat = '';
        for (let i = 0; i < prelude.length; i++) {
            const c = prelude[i];
            if (c === '(') { depth++; flat += ' '; continue; }
            if (c === ')') { depth--; flat += ' '; continue; }
            flat += depth === 0 ? c : ' ';
        }
        return /\bor\b/i.test(flat);
    }

    /**
     * Resolves one already-matched (non-negated or negated) feature value
     * against the target scheme.
     *
     * @param  {string}  featScheme  'dark' or 'light' as written in the feature.
     * @param  {string}  scheme      The scheme we are resolving for.
     * @param  {boolean} negated     True when the feature was wrapped in `not (…)`
     *                               (or the legacy `not all and (…)`), so the
     *                               feature's plain meaning is inverted.
     * @return {{kind: 'keep', matched: true}|{kind: 'drop'}}
     */
    function resolveFeature(featScheme, scheme, negated) {
        // Only two schemes exist, so "not dark" and "not light" each collapse
        // to the other concrete scheme.
        const target = negated ? (featScheme === 'dark' ? 'light' : 'dark') : featScheme;
        return target === scheme ? { kind: 'keep', matched: true } : { kind: 'drop' };
    }

    /**
     * Classifies one comma-separated query (already known to mention
     * prefers-color-scheme somewhere) against the target scheme.
     *
     * @return {{kind: 'bail'}
     *         |{kind: 'drop'}
     *         |{kind: 'keep', text: string, matched: boolean}}
     */
    function classifyQuery(query, scheme) {
        let m;

        if ((m = query.match(RE_NOT_ALL_AND_FORM))) {
            const r = resolveFeature(m[1].toLowerCase(), scheme, true);
            return r.kind === 'drop' ? r : { kind: 'keep', text: 'all', matched: true };
        }
        if (RE_BOOLEAN_FORM.test(query)) {
            return { kind: 'keep', text: 'all', matched: false };
        }
        if ((m = query.match(RE_VALUE_FORM))) {
            const r = resolveFeature(m[1].toLowerCase(), scheme, false);
            return r.kind === 'drop' ? r : { kind: 'keep', text: 'all', matched: true };
        }
        if ((m = query.match(RE_NOT_WRAPPED_FORM))) {
            const r = resolveFeature(m[1].toLowerCase(), scheme, true);
            return r.kind === 'drop' ? r : { kind: 'keep', text: 'all', matched: true };
        }

        // Not a whole-query form: try splitting on top-level "and" so a mixed
        // query like "(min-width: 40em) and (prefers-color-scheme: dark)" can
        // keep the other feature once the scheme term is resolved.
        const terms = splitTopLevelAnd(query);
        if (terms.length > 1) {
            const kept = [];
            let schemeMentions = 0;
            let matchedOut = false;

            for (const term of terms) {
                if (!RE_MENTIONS_SCHEME.test(term)) { kept.push(term); continue; }

                schemeMentions++;
                // Both schemes referenced in one query — deliberately conservative,
                // we do not confidently know what the author intended.
                if (schemeMentions > 1) return { kind: 'bail' };

                if (RE_BOOLEAN_FORM.test(term)) continue; // always true; strip, no matched

                let tm;
                if ((tm = term.match(RE_VALUE_FORM))) {
                    const r = resolveFeature(tm[1].toLowerCase(), scheme, false);
                    if (r.kind === 'drop') return { kind: 'drop' };
                    matchedOut = true;
                    continue;
                }
                if ((tm = term.match(RE_NOT_WRAPPED_FORM))) {
                    const r = resolveFeature(tm[1].toLowerCase(), scheme, true);
                    if (r.kind === 'drop') return { kind: 'drop' };
                    matchedOut = true;
                    continue;
                }

                // A term that mentions prefers-color-scheme in a form we do not
                // confidently understand.
                return { kind: 'bail' };
            }

            return kept.length === 0
                ? { kind: 'keep', text: 'all', matched: matchedOut }
                : { kind: 'keep', text: kept.join(' and '), matched: matchedOut };
        }

        // A single term mentioning prefers-color-scheme in a form we do not
        // recognise (e.g. a range syntax, or something using `selector()`-like
        // nesting). Bail rather than risk mangling it.
        return { kind: 'bail' };
    }

    /**
     * Classifies a whole @media prelude (its raw text between "@media" and the
     * opening "{") against the target scheme.
     *
     * @return {{bail: true}
     *         |{bail: false, dropped: true}
     *         |{bail: false, dropped: false, newPrelude: string, matched: boolean}}
     */
    function classifyPrelude(rawPrelude, scheme) {
        if (hasTopLevelOr(rawPrelude)) return { bail: true };

        const queries = splitTopLevelByComma(rawPrelude);
        const kept = [];
        let matched = false;

        for (const rawQuery of queries) {
            const query = rawQuery.trim();

            if (!RE_MENTIONS_SCHEME.test(query)) {
                kept.push(query);
                continue;
            }

            const action = classifyQuery(query, scheme);
            if (action.kind === 'bail') return { bail: true };
            if (action.kind === 'drop') continue;

            kept.push(action.text);
            if (action.matched) matched = true;
        }

        if (kept.length === 0) return { bail: false, dropped: true };
        return { bail: false, dropped: false, newPrelude: kept.join(', '), matched };
    }

    // -------------------------------------------------------------------------
    //  The recursive walker
    // -------------------------------------------------------------------------

    /**
     * Walks `css`, rewriting every @media rule (at any nesting depth — inside
     * another @media, @supports, @layer, …) whose prelude mentions
     * prefers-color-scheme. Everything else — including a plain rule's own
     * braces — is copied through untouched: the walker never depth-tracks a
     * brace it is not explicitly extracting an @media block from, which is
     * what lets one flat recursive scan handle arbitrary nesting.
     *
     * @return {{css: string, matched: boolean}}
     */
    function walk(css, scheme) {
        let out = '';
        let matched = false;
        const n = css.length;
        let i = 0;

        while (i < n) {
            const c = css[i];

            if (c === '/' && css[i + 1] === '*') {
                const end = css.indexOf('*/', i + 2);
                if (end === -1) { out += css.slice(i); i = n; break; }
                out += css.slice(i, end + 2);
                i = end + 2;
                continue;
            }

            if (c === '"' || c === '\'') {
                const j = skipString(css, i);
                if (j === -1) { out += css.slice(i); i = n; break; }
                out += css.slice(i, j);
                i = j;
                continue;
            }

            if (isMediaTokenAt(css, i)) {
                const tokenEnd = i + 6;
                const bracePos = findPreludeBrace(css, tokenEnd);
                if (bracePos === -1) { out += css.slice(i); i = n; break; }

                const closeBrace = findMatchingBrace(css, bracePos);
                if (closeBrace === -1) { out += css.slice(i); i = n; break; }

                const rawPrelude = css.slice(tokenEnd, bracePos);
                const body = css.slice(bracePos + 1, closeBrace);

                if (!RE_MENTIONS_SCHEME.test(rawPrelude)) {
                    // This rule's own condition says nothing about colour scheme:
                    // keep its prelude byte-identical, but still recurse into its
                    // body — a nested @media/@supports/@layer inside it may.
                    const inner = walk(body, scheme);
                    out += css.slice(i, bracePos + 1) + inner.css + '}';
                    matched = matched || inner.matched;
                    i = closeBrace + 1;
                    continue;
                }

                const classified = classifyPrelude(rawPrelude, scheme);

                if (classified.bail) {
                    // Deliberately conservative: leave the block exactly as found,
                    // contents included. A stylesheet that behaves as it does
                    // today is a far better failure mode than one we mangled.
                    out += css.slice(i, closeBrace + 1);
                    i = closeBrace + 1;
                    continue;
                }

                if (classified.dropped) {
                    // Every comma-branch evaluated false for this scheme: the
                    // whole rule (and everything nested inside it) is discarded.
                    i = closeBrace + 1;
                    continue;
                }

                const inner = walk(body, scheme);
                out += '@media ' + classified.newPrelude + ' {' + inner.css + '}';
                matched = matched || classified.matched || inner.matched;
                i = closeBrace + 1;
                continue;
            }

            out += c;
            i++;
        }

        return { css: out, matched };
    }

    // -------------------------------------------------------------------------
    //  Public API
    // -------------------------------------------------------------------------

    /**
     * Rewrites a stylesheet's `prefers-color-scheme` media queries so they no
     * longer depend on the (misreported, see the header comment) webview
     * preference: a query requiring `scheme` has the feature stripped so its
     * block applies unconditionally; a query requiring the other scheme is
     * removed, dropping the whole @media block if nothing else keeps it alive.
     * Everything else — including the common case of a stylesheet with no
     * prefers-color-scheme at all — is returned byte-identical.
     *
     * @param  {string} css     A stylesheet's text (the site's editor.css).
     *                          Falsy is treated as ''.
     * @param  {string} scheme  'light' or 'dark' — the authoritative resolved
     *                          app theme. Anything else is treated as 'light'.
     * @return {{css: string, matched: boolean}} `matched` is true when at least
     *         one @media block was kept because it targets `scheme` — i.e. the
     *         stylesheet actually styles that colour scheme.
     */
    function resolveColorScheme(css, scheme) {
        const input = css || '';
        const resolvedScheme = scheme === 'dark' ? 'dark' : 'light';
        if (input === '') return { css: '', matched: false };

        return walk(input, resolvedScheme);
    }

    global.GrafidaCssTheme = { resolveColorScheme };
}(typeof window !== 'undefined' ? window : this));
