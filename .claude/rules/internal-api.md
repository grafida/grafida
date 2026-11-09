---
description: Grafida's internal boson:// API — the shell-php HTTP types and the two entry points (index.php via BosonBridge, bin/worker.php), the Router, the per-area controllers, exception-to-status mapping, the two-kinds-of-transport-failure rule (gh-29) and the no-store caching rule (gh-35). Lifted verbatim from CLAUDE.md's Layout section.
paths:
  - "src/Http/**"
  - "src/Application/**"
  - "src/Debug/**"
---

# The internal API (`src/Http/`)

The `src/Http/` notes from `CLAUDE.md`'s `## Layout` section. Verbatim — the leading `- ` and
two-space continuation indent are the original bullet formatting.

- `src/Http/` — `HttpClient` (curl/stream transport to Joomla), `Json`, and the internal API.
  `ApiController` is now only a **dispatcher** (~120 lines): it assembles a `Router` from the
  controllers and maps exceptions to responses (`PublishBlockedException` → 422,
  `SecureStoreUnavailableException` → 409, `InsecureUrlException` → 400 (`insecure_url`),
  `BlockedRedirectException` → 502 (`blocked_redirect`), `ApiException` → 502,
  `HttpException` → 503, `\Throwable` → 500). The two URL-security exceptions differ in status
  on purpose: `InsecureUrlException` is about a URL *we were given*, so it is bad input; a blocked
  redirect is about a URL the *remote end* chose, so it is a failure of the server.
  The 422 carries **`fieldLabels`, `overridableLabels` and `canForce`** (gh-59): the first two split
  the required custom fields Grafida cannot edit by whether the draft holds a value for them, and
  `canForce` says whether a retry with `{force: true}` could succeed. The SPA's `apiFetch()` lifts
  all three onto the thrown `Error` — along with **`err.upstreamStatus`**, which is the payload's
  `status` (what the *site* answered) and is emphatically **not** `res.status`, our own kernel's 502.
  That distinction is what lets a publish tell a Joomla form-validation 400 from any other API
  failure.
  ⚠️ **Outbound traffic policy lives in `src/Http/Security/` and is enforced by `HttpClient`, not
  by its callers.** Grafida reaches the network from about a dozen services — the API client, the
  favicon fetcher, template discovery, the editor-CSS candidate walk, the site-image proxy, the
  update check, the AI proxy — several of them built from a URL that arrived over the internal API.
  A policy each of those has to *remember* is one that will eventually be forgotten at one of them,
  so it sits in the transport and a new caller is covered the day it is written. Four pieces:
  `UrlPolicy` (a two-case enum), `IpRanges`, `DnsResolver`/`SystemDnsResolver`, and `UrlGuard`.
  - **`UrlPolicy::Site` is HTTPS with no exception whatsoever**, including to `localhost` — a local
    Joomla install still receives an API token, and loopback is a reason a local *model* has no
    certificate, not a reason to stop encrypting. Every transport in the container is on this policy
    except one.
  - **`UrlPolicy::AiService` is the single exception, and `http.ai` is the single transport that
    has it.** Cleartext is permitted only when **every** address the host resolves to is in
    `IpRanges`' closed list (`0/8`, `10/8`, `127/8`, `169.254/16`, `172.16/12`, `192.168/16`, their
    IPv4-mapped IPv6 forms, `::1/128`, `64:ff9b:1::/48`, `fe80::/64`). *Every* one, not one of them:
    a name answering with both `127.0.0.1` and a public address is the DNS-rebinding shape, and an
    unresolvable name is refused rather than given the benefit of the doubt. The list is closed on
    purpose — CGNAT, multicast and the documentation ranges are **not** in it, because the test is
    "would I send an unencrypted API key here?", not "is this address special?".
  - **`ApiClient::normaliseRoot()` still rejects a non-HTTPS site URL at the input boundary, and
    must keep doing so.** Not redundant: the guard is the backstop nothing escapes, the boundary
    check is what lets the Sites form say so while the user is typing rather than storing the URL
    and failing on every later operation.
  ⚠️ **Redirects are followed by hand and vetted per hop; `CURLOPT_FOLLOWLOCATION` must stay off.**
  libcurl chases the whole chain inside one `curl_exec()`, resending the request headers — the
  `Authorization` token among them — to each hop, with no callback that can veto one; the credential
  would be gone before we could look. `HttpClient::request()` therefore loops, one `curl_exec()` per
  hop, calling `UrlGuard::assertRedirectAllowed()` before each. Three details are load-bearing:
  a hop is judged against **the URL the caller asked for, never the previous hop** (so a chain of
  individually-plausible steps cannot walk off the domain); the destination is **re-judged against
  the policy in full**, which is what stops a `301` downgrading HTTPS to cleartext; and **the method
  and body are carried across every hop, 303 included** — what `CURLOPT_POSTREDIR =>
  CURL_REDIR_POST_ALL` used to do, and dropping it is how a publish to a redirecting site silently
  no-ops (Joomla answers the downgraded GET with the unchanged article and 200 OK).
  ⚠️ **A redirect is deliberately NOT required to resolve to the same IP address as the original
  host, and adding that check would break real sites rather than protect them.** Behind an anycast
  CDN the address returned for a name varies by resolver, by PoP and by lookup; round-robin records
  rotate; `www` and the apex routinely sit on disjoint address sets because they are different CNAME
  targets. A same-IP rule rejects apex-to-`www` — the commonest redirect there is — on the majority
  of hosting in use. What secures the hop is the scheme policy plus certificate validation, which
  `requestCurl()` states explicitly (`SSL_VERIFYPEER`, `SSL_VERIFYHOST => 2`) rather than inheriting,
  because that pair is the most commonly disabled one in PHP code and leaving it implicit makes a
  decision look like an oversight. `CURLOPT_PROTOCOLS_STR` confines libcurl to http/https, so the
  transport cannot reach the local filesystem even if something got past the guard.
  `UrlGuard::baseDomain()` is a **heuristic, not the Public Suffix List** (two labels, or three when
  the TLD is two letters and the label before it is ≤3). Its only failure mode is taking *more*
  labels than a true PSL lookup, i.e. being stricter, which is the safe direction here — do not
  reuse it anywhere over-strictness is not safe, such as cookie scoping.
  ⚠️ **A transport failure is not one error, but two** (gh-29). `HttpClient::requestCurl()` now
  passes `curl_errno()` through on `HttpException`, and `isConnectivityFailure()` checks it
  against the errnos that mean "never reached a server" (DNS failure, refused/unreachable
  connection, timeout — `6`/`7`/`28`, plus the send/recv/proxy variants). `ApiController::dispatch()`
  maps a connectivity failure to `{code: "network_unreachable"}` / HTTP 503 with the raw cURL text
  demoted to a `detail` field, and anything else (a TLS handshake failure, say) to
  `{code: "transport"}` / 503 — deliberately **not** the friendly wording, since telling someone to
  check their internet connection over a bad certificate would be actively misleading. The
  stream-wrapper fallback (`requestStream()`, used when ext-curl is absent) always constructs with
  `curlErrno = 0`, so it degrades to the generic `transport` code — it has no machine-readable cause
  to classify.
  `Router` holds a real route table — `{name}` placeholders compile to anchored regexes (`{key}` →
  `[A-Za-z0-9_\-]+`, `{file}` → that plus a short extension, anything else → `\d+`; none of the
  three admits a `/` or a bare `..`, so a traversal attempt matches no pattern and dies at the
  router's own 404 rather than reaching a handler), and
  handlers resolve their controller **from the container on match**, so a request builds one
  controller, not nine. A path that matches with an unregistered method returns **405**; an
  unmatched path **404**. `RouteContext` carries the matched parameters, the parsed body and
  the request. The handlers live in `src/Http/Controller/`: `BootstrapController`,
  `SiteController`, `ArticleController`, `DraftController`, `MediaController`,
  `AiServiceController`, `AiChatController`, `SettingsController`, `HelpController` — each a
  container service taking **only** the collaborators it uses (1–7 each; the old `ApiController`
  had 24). `HelpController` (`/api/help…`, the bundled documentation — see
  `.claude/rules/documentation.md`) is the extreme case and a useful reminder of the shape: one
  dependency, no site, no network, no database. The
  abstract `Controller` base is deliberately **dependency-free** (only the `str()`/`int()` body
  parsers); the shared site/article helpers (`requireSite`, `connectedSite`, `siteArray`,
  `withCategoryTitles`, the JSON:API relationship readers) live in `Grafida\Http\SiteContext`,
  an injected collaborator — composition, not a god base class. Controllers must never call
  each other; share through the injected services.
  ⚠️ **`SiteContext::withCategoryTitles()` looks the categories up best-effort** (`categories($site,
  false, true)`), and must stay that way (gh-29). A category *title* is a decoration on a list that
  is already in hand, so a site we cannot reach must never fail the list itself — and one of its two
  callers, `DraftController::listDrafts()`, is otherwise a **purely local** read. A strict lookup
  there is what took the whole Articles screen down on an offline machine with a cold reference
  cache: the Local Articles tab needs no network at all, but the screen-level fetch threw before it
  could render, so only the error block was left. Offline, the drafts tab must work and only the
  Remote Articles tab may show an error.
  ⚠️ **Nothing the internal API answers may be cached by the webview** (gh-35). `boson://app/api/…`
  is an ordinary URL as far as WKWebView/WebView2 are concerned, so a GET whose response says
  nothing about freshness is cached heuristically — in a **disk-backed, app-scoped** cache that
  outlives an app restart *and* a local-storage reset (after a reset the next site is id 1 again,
  so the very same URL can be answered from a pre-reset response with our PHP never running).
  This was found while investigating gh-35 and is **not** what caused it — that was a
  `reference_cache` snapshot from the site the record was originally connected to — but it is a
  real hazard with the same symptom, so it is closed off. Two independent opt-outs:
  the SPA's single `apiFetch()` chokepoint sends **`cache: 'no-store'`** — the load-bearing one,
  since suppressing the *lookup* is what makes an already-poisoned entry self-heal — and
  `Http\Json::response()` sets `Cache-Control: no-store` on every response so they are
  self-describing for any caller that does not go through `apiFetch()`. Note this is unrelated to
  the **`reference_cache`** SQLite cache (see `src/Reference/`), which is deliberately permanent
  and authoritative for rendering — the manual Refresh button remains — but which the SPA also
  quietly freshens in the background since gh-42 (see `src/Reference/` and the `assets/private/`
  notes below).
  `SiteController` also exposes **Diagnose Connection** (`POST /api/sites/diagnose`, delegating
  to `Site\ConnectionDiagnostics`) alongside the existing `/api/sites/test`, and
  `SettingsController` exposes the Request Log (gh-37, see `src/Debug/`): `POST
  /api/settings/request-log` (the on/off toggle), `GET /api/request-log` (the stored entries),
  `POST /api/request-log/clear`, and `POST /api/request-log/export` — which, like
  `DraftExportService`'s `.grafida` export, asks for a destination **folder** rather than a
  file (`POST /api/dialog/select-directory`): Boson's `DialogApiInterface` has no Save-As
  dialog, so the filename (`grafida-request-log-<timestamp>.json`) is derived and the file
  written server-side instead.
  `SettingsController` is also where the invisible-character clean-up is *applied*, not just
  configured (`POST /api/settings/content-normalisation`): `convertMarkdown()` normalises the
  imported source **before** CommonMark sees it, and `clipboardText()` normalises what it hands
  back. ⚠️ `ClipboardService` itself stays a dumb reader — the same separation as
  `HttpClient`/`RecordingTransport` — so the policy lives in the controller and the OS-specific
  code stays testable without one.

# HTTP types and the two entry points (gh-84)

Everything behind `index.php` speaks **grafida/shell-php**'s types: `Grafida\Shell\Request`,
`Response`, `Headers` and `StaticFiles`. No Boson HTTP type appears in `src/` except in
`Grafida\Application\BosonBridge`. `Kernel`, `Router`, `RouteContext`, `Json`, `ApiController`,
`FrontController` and every controller take and return the shell-php types.

- **Constructors are Boson's, on purpose.** `new Request($method, $url, $headers, $body)` and
  `new Response($body, $status, $headers)` have Boson's positional signatures, and `Headers`
  iterates **one `name => value` pair per value** (as Boson's `HeadersMap` did). That is what let
  the Feature suite, which is the API contract, switch type with only its `use` lines and two helper
  type hints changed and no assertion edits. Keep it that way.
- **Reading a request:** `$request->path()` (raw, still percent-encoded; `/` when absent),
  `$request->query('name')` (decoded like `parse_str()`, first element of an array, as Boson's
  `Query::get()` did), `$request->header('name')`, `$request->body`. `status` is a plain `int`.
- **Headers keep every value of a repeated header end to end** (lower-case name → list). Names
  must be tokens and values must not contain CR/LF/NUL, and `Headers` refuses both **at
  construction**. So a controller that builds a header from user data (`Content-Disposition`'s
  filename in `MediaController`) fails as a 500 from `ApiController`'s `\Throwable` catch rather
  than smuggling a header. That is not new: Boson's `Header::validateHeaderValueOrFail()` (an
  RFC 7230 pattern, stricter than ours) refused the same values.
- **`BosonBridge` passes Boson's re-serialised URL** (`(string) $request->url`), not the raw
  one Boson keeps private. That parsed form (empty path segments dropped, query re-encoded) is
  what the kernel always saw, so routing and static lookups are unchanged. It **drops**, rather
  than fails on, a request header shell-php refuses. Under Boson a repeated *response* header still
  reaches the webview once: saucer's `std::map` collapses it (ADR 0001), and the shell fixes that.
- **`StaticFiles`** replaces Boson's `FilesystemStaticProvider`. It serves GET/HEAD only, decodes
  the path, and refuses `..`, backslashes and NUL lexically, because `realpath()` cannot see inside
  a PHAR. An unknown extension is `application/octet-stream` (Boson defaulted to `text/html`), and
  JavaScript is `text/javascript`. `find()` answers null on a miss, which is what lets `Kernel`
  fall through to the SPA shell.
- **`ContainerFactory` wraps invokable parameters.** joomla/di treats any callable value handed to
  `share()`/`set()` as a factory, so `StaticFiles` (a `Handler`, therefore invokable) would be
  *called with the container*. `BosonBridgeTest::testAnInvokableContainerParameterIsReturnedNotCalled`
  pins it. The same applies to any future invokable service you register as a value.
- **`bin/worker.php`** is the second entry point: the same container, served by shell-php's
  `Worker::run()` over stdin/stdout in `GSF1` frames (grafida/shell `protocol/v1.md`). Exit status:
  0 on EOF/`shutdown`, 65 on a protocol error, 74 when stdout is gone. A handler that throws is a
  `500` response, never an exit. Anything printed during a request goes to stderr as
  `[stray-output]` and never into the stream. `ApiController` already turns exceptions into JSON
  errors, so the worker's own 500 is a last resort.
- **Files picked in the shell's dialogs arrive as `X-Shell-File-Path` (gh-85), read through
  `Grafida\Http\ShellFile`.** `ShellFile::path($request)` is the picked file;
  `ShellFile::directory($request, $body)` is an export's destination, the header winning over the
  body's `directory` (which is all Boson can send). The route closures pass it in, so the handler
  methods (`openFile()`, `exportDraft()`, `exportRequestLog()`, `saveLocalMediaToDisk()`) keep their
  Boson-era behaviour for a request without the header, 503-without-a-dialog included. The header is
  trustworthy only because the shell strips whatever the page sent; ⚠️ **under Boson nothing would, so
  `BosonBridge::headers()` drops `x-shell-file-path`, `x-shell-file` and `x-shell-clipboard`** (pinned by
  `BosonBridgeTest::testThePageCannotNameAFileUnderBoson`). `tests/Feature/ShellFileTest.php` covers the
  header path; the pipe differential has a scenario for it (a step's optional fourth element is its
  request headers). The clipboard works the same way (`Grafida\Http\ShellClipboard`): the shell
  reads it and sends the text as the body of `GET /api/clipboard/text` with `X-Shell-Clipboard: text`.
- **`tests/Pipe/` (`composer test:pipe`)** is the proof. It plays every grafida/shell conformance
  fixture (shipped inside shell-php, `Grafida\Shell\Testing\Fixtures`) into a real
  `bin/worker.php`. It also runs a **differential**: Feature-suite request sequences answered by the
  same container (`PipeApp`) in process and through `feature-worker.php`, where status, every header
  and every body byte must match (JSON timestamps masked). The comparison sees what no single
  assertion looks at; a mutation check showed one injected response header failing it. When a
  new route matters to the worker, add its requests to a scenario there, not a Feature-test edit.

# The native event loop (`src/Application/BosonApplication`, `EventLoopThrottle`)

⚠️ **Boson's event loop is a busy-wait, and it costs about half a CPU core with the app idle.**
`Boson\Application::run()` is `do { $poller->next(); } while ($this->isRunning)`, and the stock
`SaucerPoller::next()` separates two iterations by `usleep(1)` — *one microsecond*. It rotates
between three task types, and every third iteration crosses FFI into
`saucer_application_run_once()`, which pumps the entire native event loop (on macOS, a full
`-[NSApplication nextEventMatchingMask:untilDate:inMode:dequeue:]` round trip through the
CFRunLoop). At tens of thousands of iterations a second that is a permanent, load-independent CPU
burn. Measured with Grafida sitting idle on the Articles screen on an M5: **~49% of a core** in the
PHP process — while the WebKit content process that was actually rendering the window sat at 0.0%.

`EventLoopThrottle` fixes it by adding a **2 ms sleep after an idle iteration**, which takes the
same measurement to **~1% of a core**. The loop still pumps native events several hundred times a
second, i.e. an event is picked up well inside one frame at 120 Hz.

Three things about its shape are deliberate:

- **It is a decorator over the poller `Application::createApplicationPoller()` builds, not a
  replacement for it.** That poller carries a deferred task which flips `Application::$isRunning`
  and dispatches `ApplicationStarted`; the closure captures state a subclass cannot write
  (`$isRunning` is `public private(set)`, `$listener` is private), so a from-scratch poller would
  have to reimplement Boson's `@internal` microtask bookkeeping and could never start the app.
  `BosonApplication::createApplicationPoller()` therefore calls `parent::` and wraps the result.
- **The throttle must be woken, or it trades idle CPU for per-request latency.** A sleeping loop
  notices a `boson://` request up to three sleeps late. Once, that is invisible; charged to *every*
  request in a burst it is not. So `index.php` calls `$app->wake()` after the front controller
  answers, which runs the loop unthrottled for 100 ms — a page's worth of API calls goes at the
  upstream loop's full speed, and only a genuinely quiet app is throttled. Anything else that
  learns the app is busy should call `wake()` too.
- **It is not a Grafida-specific problem and the fix is not macOS-specific.** The same busy loop
  runs on WebKitGTK and WebView2; the throttle sits above the platform layer and helps everywhere.

`tests/Unit/EventLoopThrottleTest.php` pins the two behaviours that matter — an idle iteration
sleeps, an iteration inside the wake window does not.
