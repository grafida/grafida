/**
 * Grafida — Joomla content editing, untethered.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 *
 * Unit tests for media/private/js/editor/csstheme.js — the module that
 * resolves a site editor.css's prefers-color-scheme media queries against
 * Grafida's authoritative resolved theme (gh-38).
 *
 * Run with `composer test:js` (or `node --test tests/js/`). Like the
 * slashtools/providers tests, this is the ONLY automated coverage available:
 * the module lives in the SPA, so PHPUnit cannot reach it.
 *
 * csstheme.js is a plain browser IIFE that hangs itself off `window` and
 * depends on no app.js globals — it is a pure string transform — so the
 * sandbox here needs nothing faked beyond a bare `window` object.
 *
 * The sandbox is its own realm: a value returned from vm-evaluated code fails
 * a strict deep-equal against an outer-realm literal on the prototype alone.
 * We therefore assert on result.css / result.matched individually rather than
 * deep-equalling the whole returned object.
 */

import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import assert from 'node:assert/strict';
import vm from 'node:vm';

const SOURCE = readFileSync(new URL('../../media/private/js/editor/csstheme.js', import.meta.url), 'utf8');

/** Loads csstheme.js into a fresh sandbox and returns its public API. */
function load() {
    const sandbox = { window: {} };
    sandbox.globalThis = sandbox;
    vm.createContext(sandbox);
    vm.runInContext(SOURCE, sandbox);
    return sandbox.window.GrafidaCssTheme;
}

// -----------------------------------------------------------------------------
//  Dropping the non-matching scheme
// -----------------------------------------------------------------------------

test('a dark-only block is removed entirely when resolving for light', () => {
    const CssTheme = load();
    const css = '@media (prefers-color-scheme: dark) { body { color: #fff; } }';
    const result = CssTheme.resolveColorScheme(css, 'light');

    assert.equal(result.css, '');
    assert.equal(result.matched, false);
});

test('a dark-only block is unwrapped, contents preserved, when resolving for dark', () => {
    const CssTheme = load();
    const css = '@media (prefers-color-scheme: dark) { body { color: #fff; } }';
    const result = CssTheme.resolveColorScheme(css, 'dark');

    assert.match(result.css, /body\s*\{\s*color:\s*#fff;\s*\}/);
    assert.doesNotMatch(result.css, /prefers-color-scheme/);
    assert.equal(result.matched, true);
});

test('a light-only block is removed entirely when resolving for dark', () => {
    const CssTheme = load();
    const css = '@media (prefers-color-scheme: light) { body { color: #000; } }';
    const result = CssTheme.resolveColorScheme(css, 'dark');

    assert.equal(result.css, '');
    assert.equal(result.matched, false);
});

test('a light-only block is unwrapped when resolving for light', () => {
    const CssTheme = load();
    const css = '@media (prefers-color-scheme: light) { body { color: #000; } }';
    const result = CssTheme.resolveColorScheme(css, 'light');

    assert.match(result.css, /body\s*\{\s*color:\s*#000;\s*\}/);
    assert.doesNotMatch(result.css, /prefers-color-scheme/);
    assert.equal(result.matched, true);
});

// -----------------------------------------------------------------------------
//  Unwrapping while keeping the rest of the query
// -----------------------------------------------------------------------------

test('a trailing scheme feature is stripped, the rest of the query survives', () => {
    const CssTheme = load();
    const css = '@media (min-width: 40em) and (prefers-color-scheme: dark) { a { color: red; } }';
    const result = CssTheme.resolveColorScheme(css, 'dark');

    assert.match(result.css, /^@media \(min-width: 40em\) \{/);
    assert.equal(result.matched, true);
});

test('a leading scheme feature is stripped, the "and" on the other side goes too', () => {
    const CssTheme = load();
    const css = '@media (prefers-color-scheme: dark) and (min-width: 40em) { a { color: red; } }';
    const result = CssTheme.resolveColorScheme(css, 'dark');

    assert.match(result.css, /^@media \(min-width: 40em\) \{/);
    assert.equal(result.matched, true);
});

test('a lone scheme feature with nothing else becomes an unconditional query', () => {
    const CssTheme = load();
    const css = '@media (prefers-color-scheme: dark) { a { color: red; } }';
    const result = CssTheme.resolveColorScheme(css, 'dark');

    assert.match(result.css, /^@media all \{/);
    assert.match(result.css, /a\s*\{\s*color:\s*red;\s*\}/);
});

// -----------------------------------------------------------------------------
//  Comma-separated query lists
// -----------------------------------------------------------------------------

test('a comma list keeps the non-scheme query and drops the mismatching one', () => {
    const CssTheme = load();
    const css = '@media print, (prefers-color-scheme: dark) { a { color: red; } }';
    const result = CssTheme.resolveColorScheme(css, 'light');

    assert.match(result.css, /^@media print \{/);
});

test('a comma list of both schemes keeps only the matching branch', () => {
    const CssTheme = load();
    const css = '@media (prefers-color-scheme: light), (prefers-color-scheme: dark) { a { color: red; } }';
    const result = CssTheme.resolveColorScheme(css, 'dark');

    assert.notEqual(result.css, '');
    assert.doesNotMatch(result.css, /prefers-color-scheme:\s*light/);
    assert.equal(result.matched, true);
});

// -----------------------------------------------------------------------------
//  Negation
// -----------------------------------------------------------------------------

test('"not all and" is kept when it matches the resolved scheme', () => {
    const CssTheme = load();
    const css = '@media not all and (prefers-color-scheme: dark) { a { color: red; } }';
    const result = CssTheme.resolveColorScheme(css, 'light');

    assert.notEqual(result.css, '');
    assert.doesNotMatch(result.css, /prefers-color-scheme/);
    assert.equal(result.matched, true);
});

test('"not all and" is dropped when it does not match the resolved scheme', () => {
    const CssTheme = load();
    const css = '@media not all and (prefers-color-scheme: dark) { a { color: red; } }';
    const result = CssTheme.resolveColorScheme(css, 'dark');

    assert.equal(result.css, '');
});

test('the Level 4 not-wrapped form is kept for the scheme it implies', () => {
    const CssTheme = load();
    const css = '@media (not (prefers-color-scheme: dark)) { a { color: red; } }';

    const light = CssTheme.resolveColorScheme(css, 'light');
    assert.notEqual(light.css, '');
    assert.doesNotMatch(light.css, /prefers-color-scheme/);
    assert.equal(light.matched, true);

    const dark = CssTheme.resolveColorScheme(css, 'dark');
    assert.equal(dark.css, '');
});

// -----------------------------------------------------------------------------
//  Boolean form
// -----------------------------------------------------------------------------

test('the boolean form is kept for both schemes but never sets matched', () => {
    const CssTheme = load();
    const css = '@media (prefers-color-scheme) { a { color: red; } }';

    const light = CssTheme.resolveColorScheme(css, 'light');
    assert.notEqual(light.css, '');
    assert.equal(light.matched, false);

    const dark = CssTheme.resolveColorScheme(css, 'dark');
    assert.notEqual(dark.css, '');
    assert.equal(dark.matched, false);
});

// -----------------------------------------------------------------------------
//  Untouched input
// -----------------------------------------------------------------------------

test('a stylesheet with no prefers-color-scheme is returned byte-identical', () => {
    const CssTheme = load();
    const css = 'body { color: #333; } @media (min-width: 40em) { a { color: blue; } } .x::before { content: "hi"; }';

    const light = CssTheme.resolveColorScheme(css, 'light');
    assert.equal(light.css, css);
    assert.equal(light.matched, false);

    const dark = CssTheme.resolveColorScheme(css, 'dark');
    assert.equal(dark.css, css);
    assert.equal(dark.matched, false);
});

test('a top-level "or" bails out, byte-identical', () => {
    const CssTheme = load();
    const css = '@media (prefers-color-scheme: dark) or (max-width: 40em) { a { color: red; } }';

    const result = CssTheme.resolveColorScheme(css, 'light');
    assert.equal(result.css, css);
    assert.equal(result.matched, false);
});

test('mentioning both schemes in one query bails out, byte-identical', () => {
    const CssTheme = load();
    const css = '@media (prefers-color-scheme: dark) and (prefers-color-scheme: light) { a { color: red; } }';

    const result = CssTheme.resolveColorScheme(css, 'dark');
    assert.equal(result.css, css);
    assert.equal(result.matched, false);
});

// -----------------------------------------------------------------------------
//  Scanner robustness
// -----------------------------------------------------------------------------

test('a prefers-color-scheme mention inside a comment is not acted upon', () => {
    const CssTheme = load();
    const css = '/* @media (prefers-color-scheme: dark) { body { color: #fff; } } */ ' +
        '@media (prefers-color-scheme: dark) { a { color: red; } }';

    const result = CssTheme.resolveColorScheme(css, 'light');

    // The commented-out block survives verbatim; the real one is dropped.
    assert.match(result.css, /^\/\* @media \(prefers-color-scheme: dark\)/);
    assert.doesNotMatch(result.css.slice(result.css.indexOf('*/')), /prefers-color-scheme/);
});

test('a string value containing braces or "@media" does not derail brace matching', () => {
    const CssTheme = load();
    const css = '.x::before { content: "@media (prefers-color-scheme: dark) {"; } ' +
        '@media (prefers-color-scheme: dark) { a { color: red; } }';

    const result = CssTheme.resolveColorScheme(css, 'light');

    // The string survives untouched, and the REAL following @media block is
    // still correctly recognised and dropped.
    assert.match(result.css, /content: "@media \(prefers-color-scheme: dark\) \{";/);
    assert.doesNotMatch(result.css.slice(result.css.indexOf(';') + 1), /prefers-color-scheme/);
});

test('a nested @media inside @supports is handled', () => {
    const CssTheme = load();
    const css = '@supports (display: grid) { @media (prefers-color-scheme: dark) { a { color: red; } } }';

    const result = CssTheme.resolveColorScheme(css, 'light');

    assert.match(result.css, /^@supports \(display: grid\) \{\s*\}$/);
    assert.equal(result.matched, false);

    const dark = CssTheme.resolveColorScheme(css, 'dark');
    assert.match(dark.css, /a\s*\{\s*color:\s*red;\s*\}/);
    assert.equal(dark.matched, true);
});

test('a nested @media inside another @media is handled', () => {
    const CssTheme = load();
    const css = '@media (min-width: 40em) { @media (prefers-color-scheme: dark) { a { color: red; } } }';

    const light = CssTheme.resolveColorScheme(css, 'light');
    assert.match(light.css, /^@media \(min-width: 40em\) \{\s*\}$/);

    const dark = CssTheme.resolveColorScheme(css, 'dark');
    assert.match(dark.css, /a\s*\{\s*color:\s*red;\s*\}/);
    assert.equal(dark.matched, true);
});

test('truncated CSS (an unclosed block) does not throw and keeps the leading content', () => {
    const CssTheme = load();
    const css = 'body { color: red; } @media (prefers-color-scheme: dark) { a { color: blue; ';

    const result = CssTheme.resolveColorScheme(css, 'light');

    assert.ok(result.css.startsWith('body { color: red; } @media'));
    assert.equal(result.matched, false);
});

test('null/empty input returns an empty, unmatched result without throwing', () => {
    const CssTheme = load();

    assert.deepEqual(Object.assign({}, CssTheme.resolveColorScheme(null, 'light')), { css: '', matched: false });
    assert.deepEqual(Object.assign({}, CssTheme.resolveColorScheme('', 'dark')), { css: '', matched: false });
});

// -----------------------------------------------------------------------------
//  Realistic smoke test
// -----------------------------------------------------------------------------

test('a Bootstrap-5.3-shaped stylesheet loses its dark branch when resolved for light', () => {
    const CssTheme = load();
    const css = ':root { --bg: #fff; color-scheme: light }' +
        '@media (prefers-color-scheme: dark) { ' +
        ':root, [data-bs-theme=light] { --bg: #212529; color-scheme: dark } }';

    const result = CssTheme.resolveColorScheme(css, 'light');

    assert.doesNotMatch(result.css, /#212529/);
    assert.doesNotMatch(result.css, /color-scheme:\s*dark/);
    assert.match(result.css, /--bg:\s*#fff/);
});
