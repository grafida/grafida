---
name: verify
description: Drive the real Grafida SPA against the real PHP backend in a browser, to observe a change actually working.
---

# Verifying a Grafida change by running it

Grafida is a desktop app on its own native shell: a native webview whose SPA talks to
the PHP worker over the `boson://` scheme. You can drive the real window through the
shell's automation channel (see `CLAUDE.md`), but for most changes you don't need
to. **The `boson://` scheme handler is the only piece that has to be replaced.** Everything behind `Kernel::handle()` and everything in
`assets/private` is the shipping code, so an HTTP bridge + Chromium gives you a
genuine end-to-end run.

Build the harness in the scratchpad directory, never in the repo.

## Backend only (fast — a route, a service, an API shape)

`Kernel` is a pure `Request → Response` function, so drive it directly:

```php
$container = ContainerFactory::create([
    'db.path'         => __DIR__ . '/drive.sqlite',   // a file, so it persists across requests
    'base.path'       => '/path/to/grafida',
    'static.provider' => new StaticFiles([]),          // Grafida\Shell\StaticFiles; no roots = finds nothing
    'secret.store'    => false,                        // plaintext fallback: no keychain prompts
]);
$response = $container->get(Kernel::class)->handle(new Request('GET', 'boson://app/api/...'));
```

`Request`/`Response` are `Grafida\Shell\*` (grafida/shell-php): `new Request($method,
$url, $headers, $body)`, `$response->status` is an int, `$response->headers` iterates one
`name => value` pair per value. Pass every container parameter that is an invokable object through
`ContainerFactory::create()` as-is — it wraps them, because joomla/di would otherwise call them.

Seed rows with `$container->get(DatabaseInterface::class)->getConnection()` (a
real PDO; the container has already migrated the DB).

`error_reporting(E_ALL & ~E_DEPRECATED)` — joomla/database trips a PHP 8.5
deprecation that otherwise corrupts JSON output.

## Full stack (the SPA, the editor, the AI panel)

1. **Bridge**: a `php -S 127.0.0.1:8793 bridge.php` router that sends **every** request —
   static assets included — through `FrontController`, with `static.provider` set to
   `new StaticFiles([$root . '/assets/private', $root . '/assets/public'])`:
   `new Request($_SERVER['REQUEST_METHOD'], 'boson://app' . $_SERVER['REQUEST_URI'], getallheaders(), file_get_contents('php://input'))`,
   then `http_response_code($r->status)`, `header("$name: $value", false)` for each pair, echo the
   body. Do not `return false` for assets: that lets the built-in server serve them and skips
   `StaticFiles` and the SPA-shell fallback, which are app code.
2. **Browser**: `npx playwright install chromium` once, then rewrite the scheme
   before app.js loads — this is the whole trick:

```js
await page.addInitScript(() => {
    const f = window.fetch;
    window.fetch = (u, o) => f(typeof u === 'string' && u.startsWith('boson://app') ? u.replace('boson://app', '') : u, o);
});
```

Then drive the real UI: `[data-screen="articles"]`, `.articles-tab` (drafts are on the
**Local articles** tab, not the default one), `.article-item-delete`, `.article-item`,
`#ai-input`, `#ai-btn-send`, `#settings-language-select`. Modals open via the
globals (`window.openEditAiServiceModal(1)`); note `State` is a top-level
`const`, so it is **not** on `window` — probe the DOM instead.

### Fake remotes

- **A Joomla site**: `php -S` over a dir with `images/`. Set `sites.base_url` to
  it. Add `usleep()` to the router to simulate real latency — that is how the
  AI panel's image-collection dead time was found.
- **An AI provider**: a `php -S` script that records `php://input` to a file and
  echoes SSE `data: {"choices":[{"delta":{"content":"..."}}]}` + `[DONE]`.
  **It must send `Access-Control-Allow-Origin/Headers` and answer `OPTIONS`** —
  the page origin differs from the provider's, exactly like the real CORS
  constraint. Point an `ai_services` row's `endpoint` at it. The recorded file
  is your proof of what the wire format really was.

## Gotchas

- Seeding an `ai_services` row needs `insecure_key` (with `secret.store => false`).
- A site with no token makes reference/media calls 409 in the console — expected
  noise, not your bug.
- `[data-screen="requestlog"]` is `hidden` until the Request Log setting is on
  (`POST /api/settings/request-log {"enabled":true}`); default off.
- `bin/worker.php` (the stdio worker) is covered by `composer test:pipe`, not by this skill:
  a browser cannot speak the frame protocol. In the browser `underShell()` is false, so the
  file dialogs answer 503 (there is no shell to open one), and `openUrl` and the clipboard go
  through PHP.
- Identical image URLs get HTTP-cached by the browser; use distinct filenames
  when measuring per-image latency.
