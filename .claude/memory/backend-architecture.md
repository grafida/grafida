# Back-end architecture (Layout notes)

The `src/` layout notes for the composition root, internal API, Joomla client, secret stores, debug recording, storage, support code, start-up failure reporting and the worker entry point. Verbatim from the former root agent-instructions file.

- `src/Application/` — the **composition root**. `Container` is a thin `Joomla\DI\Container`
  subclass whose only job is to give `get()` a generic return type (the parent's is `mixed`,
  which PHPStan level max cannot use). `ContainerFactory::create(array $parameters = [])`
  registers the parameters, then the five `Provider/` service providers (`StorageProvider`,
  `HttpProvider`, `SiteProvider`, `AiProvider`, `AppProvider`, `ControllerProvider`). The
  parameters are the app's only configuration seams — override them and you get a different
  app without touching a constructor:
  `db.path` (default `Paths::databaseFile()`; `':memory:'` in tests), `migrations.dir`,
  `base.path`, `docs.dir` (default `Resources::docsDir()` — note it does **not** go through
  `Resources::base()`, since the docs are read straight out of the phar rather than extracted),
  `static.provider` (a `Grafida\Shell\StaticFiles`; no default, `bin/worker.php` and the tests
  supply it), and `secret.store` — which is
  **tri-state**: `null` → `SecretStoreFactory::secureStore()` (production), `false` → no store
  (forces the insecure-plaintext fallback path), a `SecretStore` instance → used as-is.
  The `DatabaseInterface` factory **connects *and* migrates**, so every consumer receives a
  migrated database. `Kernel` is `(StaticFiles, ApiController)`.
  ⚠️ **joomla/di calls any callable value as a factory**, so `create()` wraps a parameter that is
  an invokable object before sharing it. `StaticFiles` is one: it is a shell-php `Handler`, and
  without the wrap the container calls `StaticFiles::__invoke($container)` instead of returning it.
- `src/Http/` — `HttpClient` (curl/stream transport to Joomla), `Json`, and the internal API.
  `ApiController` is only a **dispatcher**; a real `Router` route table resolves each handler's
  controller from the container on match, so a request builds one controller, not nine. The
  handlers live in `src/Http/Controller/`, each taking only the collaborators it uses; the shared
  site/article helpers live in the injected `Grafida\Http\SiteContext`, **not** a god base class.
  Controllers must never call each other — share through the injected services.
  (The hand-rolled `HttpClient` exists because `joomla/http` was, at the time, uninstallable on
  the PHP version in use — its laminas-diactoros dependency capped out below it.)
  `src/Http/Security/` is the **outbound-traffic policy**, enforced inside `HttpClient` so that no
  caller can forget it: `UrlPolicy` (`Site` = HTTPS, no exceptions; `AiService` = HTTPS *unless*
  every address the host resolves to is loopback/private/link-local), `IpRanges` (the closed list of
  such blocks, v4 and v6-mapped), `DnsResolver`/`SystemDnsResolver`, and `UrlGuard`, which answers
  both "may this be requested?" and "may this redirect be followed?".
  ⚠️ **Redirects are followed by hand, not by libcurl**, and each hop is vetted *before* it is
  contacted — `CURLOPT_FOLLOWLOCATION` resends the `Authorization` header to every hop inside one
  `curl_exec()` and offers no veto, so the credential would already be gone by the time we could
  look. A hop must stay on the **same base domain** as the URL the caller asked for (never merely
  the previous hop) and must satisfy the policy afresh, so a `301` cannot downgrade to cleartext.
  ⚠️ **A redirect is deliberately NOT required to resolve to the same IP.** Behind an anycast CDN
  the address varies by resolver and PoP, round-robin records rotate, and `www` and the apex are
  routinely different CNAME targets — a same-IP rule would reject the commonest redirect on the
  Internet on most hosting in use. Certificate validation, which is stated explicitly rather than
  inherited, is what secures the hop.
  ⚠️ **Detail is in `.claude/rules/internal-api.md`**, which loads when you touch `src/Http/`,
  `src/Application/` or `src/Debug/`. Two rules worth carrying without it: **a transport failure
  is not one error but two** (a connectivity failure must not be reported the same way as a TLS
  handshake failure — telling someone to check their internet over a bad certificate is actively
  misleading), and **nothing the internal API answers may be cached by the webview** (gh-35).
- `src/Joomla/ApiClient.php` — Joomla REST client: base-URL normalisation + probing, JSON:API.
  `probeApiBase()` remembers the first **connectivity** `HttpException` across the candidate bases
  and, when no candidate ever answers, rethrows it rather than reporting "no working API endpoint
  found" (gh-29) — offline must not be blamed on the URL. An auth failure (401/403 from a candidate
  that did answer) still takes priority over a transport failure on another candidate.
- `src/Secret/` — OS secret stores (macOS `security`, Linux `secret-tool`, Windows DPAPI) + factory.
  Windows DPAPI runs through **`WindowsDpapi`** (a direct FFI call into `crypt32.dll`), **not** a
  `powershell.exe` spawn — see the Windows build note below for why (the multi-second UI stall).

- `src/Debug/` — the recording substrate behind **Diagnose Connection** and the opt-in
  **Request Log** (gh-37): `RequestRecord` (one captured HTTP exchange), `Redactor`,
  `BodyFormatter`, `RecordingTransport`, `RequestLog`/`RequestLogService`, and the
  `RecordSink` interface both `ArraySink` and `RequestLog` itself implement. The log is an
  **in-memory ring buffer** (`RequestLog`, capacity 20) — not a table — because it is cleared
  at app start, on every site switch and whenever the setting is turned off, so nothing about
  it is meant to outlive the process; the on/off flag rides in the generic `settings` key/value store
  (`request_log`, **default off** — unlike `slash_tools`/`spell_check`, which default on)
  and needs no migration. Recording is a **`Transport` decorator** (`RecordingTransport`),
  not a change to `HttpClient`, which stays a dumb transport — this is what lets *Diagnose
  Connection* (`Grafida\Site\ConnectionDiagnostics`) work with the Request Log switched off:
  it builds a throwaway `ApiClient` over a `RecordingTransport` writing to a private
  `ArraySink`, never touching the shared log. `http.default`/`http.short`/`http.reference`
  (see `HttpProvider`) are wrapped into the container-shared `RequestLog`; **`http.ai` is
  not** (AI traffic is not "requests to the site", may be huge, and carries a different
  provider's key), and **`http.diagnostics` is also deliberately unwrapped** — a diagnose
  run records into its own `ArraySink`, so wrapping this transport too would double-record
  every probe into the shared log.
  ⚠️ **Redaction is unconditional.** `RequestRecord::toArray()` is the only serialisation
  path a record ever goes through — whether it is bound for the Request Log screen, the
  Diagnose Connection panel, or the JSON export — and it always masks `Authorization`/
  `X-Joomla-Token` (and any literal occurrence of the token elsewhere in a URL or body) down
  to first-4 + dots + last-4. There is no separate export-only redaction path to fall out of
  sync with the screen.
  Bodies are **capped at 64 KiB per direction at capture time** (`BodyFormatter::cap()`,
  applied by `RecordingTransport` before a record is even built — a media upload is a
  multi-megabyte base64 blob, and keeping 20 of those in memory is not acceptable) and
  described by kind — `none`/`text`/`json`/`binary`: JSON is pretty-printed, binary renders
  as the localised "(… binary data …)" marker, and an empty body is omitted rather than
  shown blank.

- `src/Storage/` — SQLite + migrations, on **`joomla/database`**. There is **no global DB object**:
  the container owns the single `Joomla\Database\DatabaseInterface` instance. `SqliteDatabase`
  extends `Joomla\Database\Sqlite\SqliteDriver` and overrides `connect()` to apply the pragmas the
  app depends on (`journal_mode = WAL`, `foreign_keys = ON` — the AI-chat cascade deletes need it —
  and `busy_timeout = 5000`); `DatabaseFactory` builds one from a path.
  `Migrator` applies `src/sql/*.sql` in lexicographic order, exactly once each, tracked
  by file name in `schema_migrations`. **Its bookkeeping runs through the driver but each migration
  file's body is still handed to the raw `\PDO::exec()`** (via `getConnection()`) — deliberately: the
  `.sql` files hold multiple statements *and* `--` comments, which a prepared statement cannot run
  and `DatabaseDriver::splitSql()` (a naive `;` splitter that does not strip comments) would mangle.
  `04_ai_chat_response_chain.sql` is two bare `ALTER TABLE … ADD COLUMN`s and is **not** re-runnable,
  which is what makes the `schema_migrations` bookkeeping load-bearing — and why
  `StorageService::reset()` wipes every table *except* that one.
  `StorageService` reports the DB file path, opens its folder in the OS file browser
  (`open`/`explorer`/`xdg-open`), and resets local storage (deletes secrets + wipes all
  tables, keeping `schema_migrations`). Exposed under `/api/settings/storage[/open|/reset]`.
  ⚠️ `PRAGMA foreign_keys` is a **no-op inside a transaction**, so `reset()` must never be wrapped
  in one.
  ⚠️ **Every kind of OS-stored secret needs its own delete loop in `reset()`, run *before* the bulk
  wipe.** The secret store is not a table, so `DELETE FROM` reaches none of it, and once the row
  carrying the `secret_ref` is gone nothing can say which keychain entry was ours — it becomes an
  unfindable orphan. `reset()` therefore loops `SiteService::delete()` **and**
  `AiServiceManager::delete()` (the latter was missing until it was reported through the
  documentation). A future secret gets a third loop; `StorageServiceTest` pins this.

- `src/Support/` — `Resources`/`Paths` (filesystem locations; inside a PHAR, `Resources::base()`
  mirrors `language/` and `src/sql/` into the data dir on every launch, by **content**, and
  removes what the build no longer ships, gh-86), `App` (app identity/legal
  metadata: name, `VERSION`, copyright, licence + FSF URL, the verbatim Joomla! trademark
  disclaimer — sent to the SPA in the `bootstrap` payload's `app` key), and `UrlOpener`
  (opens an external http(s) URL in the OS default browser; backs `POST /api/open-url`).
  The sidebar footer shows the version and opens an About dialog using this metadata.
- `src/Startup/` — `FailureReporter::describe()`, the one thing left of the start-up checks (gh-92):
  the sentence a person can act on when the **worker** cannot build the app, most often a data
  folder it cannot write to (named, with its path). ⚠️ **That failure looks different per mode**:
  the packaged worker writes its language files and migrations into the data folder
  (`Resources::base()`) *before* it opens the database, so there it is a
  `Support\DataDirectoryException`, and only in development is it the database that fails; the Pipe
  test asserts each, so run it with `GRAFIDA_PIPE_WORKER` too. `bin/worker.php` catches a failure while it builds the
  container and the `FrontController`, writes that sentence to stderr, then the stack trace cut to
  20 frames, and exits `FailureReporter::EXIT_FAILED` (69) without sending `ready`. ⚠️ **The cut is
  load-bearing**: the shell's failure alert is the last 40 lines of the worker's stderr, and a
  chained trace alone ran to ~70, which would push the sentence out of it. The text is hard-coded
  English: it runs before the language files are loaded, or instead of them.
  `tests/Pipe/WorkerPipeTest::testAWorkerThatCannotStartSaysWhyInTheShellsAlertWindow` pins it.
  Everything that used to be here (`StartupCheck`, the native alerts, `App::MIN_MACOS`) was the
  process that owned the window, and the shell does it now (see the top of this file).

- `bin/worker.php` — the entry point (gh-84, gh-92): the container + shell-php's `Worker::run()`,
  run by the packaged SFX stub out of its PHAR, or by the system PHP in dev mode. Deliberately free
  of what belongs to the process owning the window (webview checks, spelling defaults, consoles,
  dialogs: the shell opens its own and forwards `X-Shell-File-Path`; without it `POST /api/dialog/*`
  answers 503). A failure while it builds the app is reported as `src/Startup/` describes. stdout
  carries the frame stream; never print to it. `tests/Pipe/` drives it over real pipes against a throwaway data
  directory (`HOME`/`XDG_*`/`APPDATA` pointed at a temp dir), never the user's.
