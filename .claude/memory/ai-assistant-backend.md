# AI assistant: PHP side and key facts

Read before touching `src/Ai/`, the AI controllers or `assets/private/js/ai/`. The detailed rules also auto-load from `.claude/rules/ai-assistant.md`.

- `src/Ai/` — the **AI chat assistant** (chat with an LLM about the open article, the document
  supplied as context; modelled on the Joomla AITiny plugin, **text only — no AI images**).
  `AiServiceManager` is CRUD over configured **AI services** (`ai_services`), each a named
  provider connection (provider + endpoint + model + params); the API key lives in the OS keychain
  (reference `grafida.ai_service.{id}`, insecure-plaintext fallback like sites). **Multiple services
  are supported**; one may be flagged default, else the **lowest id** wins (`default()`). `Defaults`
  loads bundled `resources/{defaults.json,voices.json,providers.json}` (ported from AITiny: the base
  **system prompt**, the writing **tools** generate/proofread/friendly/professional/concise, the
  tone-of-voice library, and the **provider table** — OpenAI, Anthropic, Cohere, DeepSeek, Google,
  Groq, MiniMax, Mistral, OpenRouter, Perplexity, Scaleway, GitHub, Custom (OpenAI Completions API),
  Custom (OpenAI Responses API) — each with endpoint/auth/chat-path/models-path/`sse_dialect`). The dialect
  is **never persisted**: `ai_services` stores only the provider *key*, and chat-path/auth/models-path/
  dialect are derived from `providers.json` at runtime, so changing the table needs no DB migration.
  `effectiveTools()` overlays the code defaults with `ai_tools` DB overrides
  + custom tools; **each tool may target its own service** (`service_id`).
  ⚠️ **An override row is written whole, but a PATCH may carry any subset of the fields** (gh-28), so
  `AiServiceController::updateAiTool()` fills what the body omits from `effectiveTool($key)` — the
  tool *as it currently resolves*, bundled defaults included — never from the override row alone.
  "Omits" means the key is **absent**: an explicit `serviceId: null` is the form's "use the default
  service" and clears the tool's service (treating it as omitted pinned a tool to its service for good).
  Falling back to the row alone is what let the list's enable/disable toggle (which sends nothing but
  `enabled`) blank a bundled tool's title, icon, prompt and tone the first time it was pressed; and
  `isCustom` is likewise preserved from the existing row, since a custom tool demoted to a built-in
  override matches no bundled key and disappears from the list entirely. That damage went unnoticed
  because `effectiveTools()` used to **ignore** an override's `title`/`icon`/`override_system` — which
  was the reported bug: a saved icon never took effect. Those three are authoritative now, so a
  pre-0.3 toggle-written row (recognised by `Defaults::isToggleOnlyRow()`: a title equal to the tool
  key, or empty — never something the edit form sends) contributes **only** its `enabled` flag.
  `AiChatRepository` persists
  **saved chats** (`ai_chats` + `ai_chat_messages`) linked to a draft; deleting the draft cascades
  them away. `ai_chats` also carries the Responses-API conversation chain
  (`previous_response_id` + `last_response_at`, the latter **ISO-8601 UTC** — unlike the other
  timestamp columns — because the SPA compares it against `Date.now()` and WKWebView's `Date.parse()`
  does not reliably handle the naive `gmdate('Y-m-d H:i:s')` form); see the AI facts below.
  Transport is deliberately **inverted vs. AITiny — see the AI transport facts below**.
  Endpoints: `/api/ai/services[...]` (+ `/default`, `/resolved`), `/api/ai/tools[...]`,
  `/api/ai/system-prompt`, `/api/ai/proxy`, `/api/ai/render` (sanitise a reply for display — see the
  AI facts), `/api/ai/chats[...]`, `/api/drafts/{id}/chats`.

## Key AI assistant facts

⚠️ **These live in `.claude/rules/ai-assistant.md`**, which loads automatically when you work
under `src/Ai/`, `assets/private/js/ai/` or the AI controllers.

Two things to carry around without it. **Transport is inverted vs. AITiny (JS-primary, not
PHP-primary):** the `shell://` kernel cannot stream, so the provider call runs in the SPA's
JavaScript, which streams the SSE response token-by-token; PHP stays the source of truth for
services, prompts/tools and saved chats. **Do not "fix" the API key being handed to local JS by
moving the call back to PHP — that kills streaming**; it is a deliberate desktop-only trade-off.
And the assistant is **text only — no AI images**.

⚠️ **Because the streaming call is made by JavaScript, PHP cannot block it — so the URL policy is
enforced where PHP still has leverage: `GET /api/ai/services/{id}/resolved`.** That endpoint hands
the SPA the endpoint URL *and* the API key, so refusing to answer it is refusing the request. It
rejects a cleartext endpoint that is not loopback/private/link-local with a 400 + `insecure_url`,
and the key is not in the refusal; `createAiService()`/`updateAiService()` apply the same check so
the Settings form fails while the user is looking at it. The SPA's streaming `fetch()` passes
**`redirect: 'error'`** for the same reason — `fetch()` cannot vet a hop, only refuse them all, and
the resulting `TypeError` is already the signal that falls back to `POST /api/ai/proxy`, where PHP
follows the redirect under `UrlGuard` or not at all.
⚠️ **AI traffic must never reach the Request Log** — it carries a third party's key, the whole
article and the whole conversation, and the log is exportable to a file people attach to bug
reports. The separation is structural (`http.ai` is the one transport not wrapped in a
`RecordingTransport`) and pinned by `tests/Unit/Http/RequestLogIsolationTest.php`.

The rules file covers the three SSE dialects and where they branch, Responses-API conversation
chaining, the CORS/ATS traps behind "the interface freezes" with a local model, the multimodal
opt-in and image-collection paths, and the panel UI including the server-side sanitising renderer.
