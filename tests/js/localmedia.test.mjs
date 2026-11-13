/**
 * Grafida — Joomla content editing, untethered.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 *
 * Unit tests for assets/private/js/editor/localmedia.js — the module that
 * builds/parses the shell://app/api/media/{id}/raw?rev=… local-media URL
 * (gh-36).
 *
 * Run with `composer test:js` (or `node --test tests/js/`). Like the
 * slashtools/csstheme tests, this is the ONLY automated coverage available:
 * the module lives in the SPA, so PHPUnit cannot reach it — but its
 * token()/url() output MUST agree byte-for-byte with the PHP side
 * (Grafida\Media\LocalMediaUrl::token()/build()), which is why the first
 * assertion below pins a value cross-checked against
 * `php -r 'echo substr(sha1("2026-07-21 10:00:00|5"), 0, 8);'` rather than
 * merely round-tripping through this module's own functions.
 */

import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import assert from 'node:assert/strict';
import vm from 'node:vm';

const SOURCE = readFileSync(new URL('../../assets/private/js/editor/localmedia.js', import.meta.url), 'utf8');

/** Loads localmedia.js into a fresh sandbox and returns its public API. */
function load() {
    const sandbox = { window: {} };
    sandbox.globalThis = sandbox;
    vm.createContext(sandbox);
    vm.runInContext(SOURCE, sandbox);
    return sandbox.window.GrafidaLocalMedia;
}

test('token() matches the PHP formula (sha1("<revisedAt>|<id>") first 8 hex chars)', () => {
    const M = load();
    // php -r 'echo substr(sha1("2026-07-21 10:00:00|5"), 0, 8);' => 206081bb
    assert.equal(M.token(5, '2026-07-21 10:00:00'), '206081bb');
});

test('token() changes when either the id or the revision changes', () => {
    const M = load();
    const base = M.token(5, '2026-07-21 10:00:00');
    assert.notEqual(M.token(6, '2026-07-21 10:00:00'), base);
    assert.notEqual(M.token(5, '2026-07-21 10:00:01'), base);
});

test('url() builds the shell://app/api/media/{id}/raw?rev=<token> shape', () => {
    const M = load();
    assert.equal(
        M.url(5, '2026-07-21 10:00:00'),
        'shell://app/api/media/5/raw?rev=206081bb',
    );
});

test('idFromUrl() recovers the id from a URL minted by url()', () => {
    const M = load();
    const url = M.url(42, '2026-01-01 00:00:00');
    assert.equal(M.idFromUrl(url), 42);
});

test('idFromUrl() tolerates a missing/extra query string', () => {
    const M = load();
    assert.equal(M.idFromUrl('shell://app/api/media/7/raw'), 7);
    assert.equal(M.idFromUrl('shell://app/api/media/7/raw?rev=deadbeef'), 7);
    assert.equal(M.idFromUrl('shell://app/api/media/7/raw?rev=deadbeef&foo=1'), 7);
});

test('idFromUrl() returns null for anything not the local /raw form', () => {
    const M = load();
    assert.equal(M.idFromUrl('data:image/png;base64,abcd'), null);
    // A real site URL that merely happens to contain "/api/media/" must not
    // be mistaken for a local reference — anchored on the shell:// prefix.
    assert.equal(M.idFromUrl('https://example.com/api/media/1/raw'), null);
    assert.equal(M.idFromUrl('shell://app/api/media/7'), null);
    assert.equal(M.idFromUrl('shell://app/api/media/7/other'), null);
    assert.equal(M.idFromUrl('shell://app/api/media/abc/raw'), null);
    assert.equal(M.idFromUrl(''), null);
    assert.equal(M.idFromUrl(null), null);
    assert.equal(M.idFromUrl(undefined), null);
});

/*
 * fitDimensions() — the gh-43 sizing rule. One case per row of
 * .plans/00-overview.md's truth table, plus the string-attribute and
 * no-change-needed cases. Must stay identical to Grafida\Media\ImageDimensions::fit().
 *
 * The sandbox is its own realm (see the module doc comment / CLAUDE.md's
 * "test:js" note), so a plain object *returned* from it fails a strict
 * deep-equal against an outer-realm literal on the prototype alone —
 * `fit()` below re-homes it with a plain field-by-field comparison instead
 * of `assert.deepEqual`.
 */

/** Compares a {width, height}|null result field-by-field, sandbox-realm-safe. */
function fit(...args) {
    const M = load();
    const result = M.fitDimensions(...args);

    return result === null ? null : { width: result.width, height: result.height };
}

test('fitDimensions() leaves untouched when neither attribute is present', () => {
    assert.equal(fit(null, null, 640, 480, 1280, 960), null);
    assert.equal(fit(undefined, undefined, 640, 480, 1280, 960), null);
});

test('fitDimensions() adopts the new intrinsic size wholesale when never hand-resized (both attrs)', () => {
    assert.deepEqual(fit(640, 480, 640, 480, 1280, 960), { width: 1280, height: 960 });
});

test('fitDimensions() adopts wholesale when only width is present and matches the old width', () => {
    // No height attribute at all: "height == oh when present" is vacuously true.
    assert.deepEqual(fit(640, null, 640, 480, 1280, 960), { width: 1280, height: 960 });
});

test('fitDimensions() keeps the width and re-ratios the height for a deliberate in-article size', () => {
    // width (300) does not match the old intrinsic width (640): a hand-resize.
    assert.deepEqual(fit(300, 225, 640, 480, 1280, 640), { width: 300, height: 150 });
});

test('fitDimensions() treats a present height that does not match the old height as a deliberate resize too', () => {
    // width matches old width, but height does NOT match old height — not the
    // "never hand-resized" case (the table's "when present" qualifier), so
    // width is kept and height is re-ratioed (round(640 * 800 / 1200) = 427)
    // instead of adopting the new intrinsic size wholesale.
    assert.deepEqual(fit(640, 100, 640, 480, 1200, 800), { width: 640, height: 427 });
});

test('fitDimensions() keeps the height and re-ratios the width when only height is present', () => {
    assert.deepEqual(fit(null, 240, 640, 480, 1280, 960), { width: 320, height: 240 });
});

test('fitDimensions() leaves untouched when any intrinsic dimension is unknown (null or zero)', () => {
    assert.equal(fit(640, 480, null, 480, 1280, 960), null);
    assert.equal(fit(640, 480, 640, 0, 1280, 960), null);
    assert.equal(fit(640, 480, 640, 480, null, 960), null);
    assert.equal(fit(640, 480, 640, 480, 1280, 0), null);
});

test('fitDimensions() coerces string DOM-attribute values', () => {
    assert.deepEqual(fit('640', '480', '640', '480', '1280', '960'), { width: 1280, height: 960 });
});

test('fitDimensions() returns null when the computed values already match the current attributes', () => {
    // Old and new intrinsic sizes are identical, so the wholesale-adopt result
    // equals what is already on the tag — nothing to write.
    assert.equal(fit(640, 480, 640, 480, 640, 480), null);
});

test('fitDimensions() rounds to the nearest pixel with a minimum of 1', () => {
    // width 1 kept (attrW does not match oldW, so it is a deliberate size);
    // the re-ratioed height (1 * 1 / 1000000) rounds to 0 without the floor.
    assert.deepEqual(fit(1, null, 500, 500, 1000000, 1), { width: 1, height: 1 });
});
