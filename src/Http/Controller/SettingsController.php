<?php

/**
 * Grafida — Joomla content editing, untethered.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Http\Controller;

use Boson\Api\Dialog\DialogApiInterface;
use Grafida\Shell\Response;
use Grafida\Clipboard\ClipboardService;
use Grafida\Debug\RequestLog;
use Grafida\Debug\RequestLogService;
use Grafida\Debug\RequestRecord;
use Grafida\Display\DisplayModeService;
use Grafida\Editor\AutoCloseTagsService;
use Grafida\Editor\SlashToolsService;
use Grafida\Editor\SpellCheckService;
use Grafida\Http\Json;
use Grafida\Http\RouteContext;
use Grafida\Http\Router;
use Grafida\Http\ShellClipboard;
use Grafida\Http\ShellFile;
use Grafida\I18n\LanguageService;
use Grafida\I18n\UiStrings;
use Grafida\Markdown\MarkdownService;
use Grafida\Reference\MetadataCacheService;
use Grafida\Site\LastSiteService;
use Grafida\Storage\StorageService;
use Grafida\Support\App;
use Grafida\Support\UrlOpener;
use Grafida\Text\ContentNormalisationService;
use Grafida\Text\ContentNormaliser;
use Grafida\Update\UpdateService;

/**
 * Handles the miscellaneous settings/system endpoints: language, display
 * mode, the editor's slash commands, the AI-content clean-up, storage
 * maintenance, the update checker, Markdown conversion, the native URL opener,
 * the Request Log (gh-37's Debug setting), the OS clipboard reader, and the
 * native file/directory dialogs.
 */
final class SettingsController extends Controller
{
    public function __construct(
        private readonly MarkdownService $markdown,
        private readonly LanguageService $language,
        private readonly DisplayModeService $displayMode,
        private readonly SlashToolsService $slashTools,
        private readonly SpellCheckService $spellCheck,
        private readonly AutoCloseTagsService $autoCloseTags,
        private readonly ContentNormalisationService $normalisation,
        private readonly ContentNormaliser $normaliser,
        private readonly LastSiteService $lastSite,
        private readonly UrlOpener $urlOpener,
        private readonly UpdateService $updates,
        private readonly StorageService $storage,
        private readonly RequestLog $requestLog,
        private readonly RequestLogService $requestLogService,
        private readonly MetadataCacheService $metadataCache,
        private readonly ClipboardService $clipboard,
        private readonly ?DialogApiInterface $dialog = null,
    ) {}

    public function registerRoutes(Router $router): void
    {
        $router->add('POST', '/api/markdown', fn (RouteContext $ctx): Response => $this->convertMarkdown($ctx->body()));
        $router->add('POST', '/api/settings/language', fn (RouteContext $ctx): Response => $this->setLanguage($ctx->body()));
        $router->add('POST', '/api/settings/display-mode', fn (RouteContext $ctx): Response => $this->setDisplayMode($ctx->body()));
        $router->add('GET', '/api/settings/system-theme', fn (RouteContext $ctx): Response => $this->systemTheme());
        $router->add('POST', '/api/settings/slash-tools', fn (RouteContext $ctx): Response => $this->setSlashTools($ctx->body()));
        $router->add('POST', '/api/settings/spell-check', fn (RouteContext $ctx): Response => $this->setSpellCheck($ctx->body()));
        $router->add('POST', '/api/settings/auto-close-tags', fn (RouteContext $ctx): Response => $this->setAutoCloseTags($ctx->body()));
        $router->add('POST', '/api/settings/content-normalisation', fn (RouteContext $ctx): Response => $this->setContentNormalisation($ctx->body()));
        $router->add('POST', '/api/settings/last-site', fn (RouteContext $ctx): Response => $this->setLastSite($ctx->body()));
        $router->add('POST', '/api/settings/request-log', fn (RouteContext $ctx): Response => $this->setRequestLog($ctx->body()));
        $router->add('POST', '/api/settings/metadata-cache', fn (RouteContext $ctx): Response => $this->setMetadataCache($ctx->body()));
        $router->add('GET', '/api/request-log', fn (RouteContext $ctx): Response => $this->requestLog());
        $router->add('POST', '/api/request-log/clear', fn (RouteContext $ctx): Response => $this->clearRequestLog());
        $router->add('POST', '/api/request-log/export', fn (RouteContext $ctx): Response => $this->exportRequestLog(['directory' => ShellFile::directory($ctx->request(), $ctx->body())] + $ctx->body()));
        $router->add('GET', '/api/update', fn (RouteContext $ctx): Response => $this->updateStatus());
        $router->add('GET', '/api/settings/storage', fn (RouteContext $ctx): Response => $this->storageInfo());
        $router->add('POST', '/api/settings/storage/open', fn (RouteContext $ctx): Response => $this->openStorageFolder());
        $router->add('POST', '/api/settings/storage/reset', fn (RouteContext $ctx): Response => $this->resetStorage());
        $router->add('POST', '/api/open-url', fn (RouteContext $ctx): Response => $this->openUrl($ctx->body()));
        $router->add('GET', '/api/clipboard/text', fn (RouteContext $ctx): Response => $this->clipboardText(ShellClipboard::text($ctx->request())));
        $router->add('POST', '/api/dialog/open-file', fn (RouteContext $ctx): Response => $this->openFile($ctx->body(), ShellFile::path($ctx->request())));
        $router->add('POST', '/api/dialog/select-directory', fn (RouteContext $ctx): Response => $this->selectDirectory($ctx->body()));
    }

    /**
     * The OS clipboard as plain text, for the editor's Cmd/Ctrl+Shift+V
     * "paste as plain text" shortcut.
     *
     * ⚠️ This exists because reading the clipboard from JavaScript is not free
     * on the webviews we ship — WKWebView demands a click on a "Paste" callout
     * first. {@see ClipboardService} has the detail. An unreadable clipboard is
     * an error rather than an empty string, so the SPA can tell the user why
     * nothing was pasted instead of silently doing nothing.
     *
     * The text is normalised on the way through ({@see ContentNormaliser}):
     * pasting out of a chatbot's web page is one of the commonest ways an
     * invisible mark gets into an article, and "paste as plain text" is a
     * request for the words, not for what is hiding between them.
     */
    public function clipboardText(?string $shellText = null): Response
    {
        // Under the native shell the shell has already read it (ShellClipboard).
        $text = $shellText !== null ? ClipboardService::normaliseLineEndings($shellText) : $this->clipboard->readText();

        if ($text === null) {
            return Json::error('Could not read the clipboard', 500);
        }

        return Json::ok(['text' => $this->normaliser->apply($text)]);
    }

    /** @param array<string, mixed> $body */
    public function openUrl(array $body): Response
    {
        $this->urlOpener->open($this->str($body, 'url'));

        return Json::ok();
    }

    /**
     * Return the bytes of a file the user picked in a native dialog.
     *
     * Two hosts, one answer (`{name, mime, dataBase64}`). Under the native shell the SPA has already
     * run the shell's own dialog and hands us its token; the shell resolves it and forwards the path
     * as `X-Shell-File-Path` ({@see ShellFile}), which arrives here as `$shellPath`. Under Boson,
     * whose webview does not wire up `<input type="file">` (WKWebView on macOS, WebKitGTK on Linux),
     * we drive the OS picker through Boson's Dialog API ourselves. Either way the SPA feeds the file
     * into the normal media-upload / Markdown-import flow.
     *
     * @param array<string, mixed> $body
     */
    public function openFile(array $body, ?string $shellPath = null): Response
    {
        if ($shellPath !== null) {
            $path = $shellPath;
        } elseif ($this->dialog === null) {
            return Json::error('Native file dialog is unavailable', 503);
        } else {
            $filter = match ($this->str($body, 'filter', 'any')) {
                'image'    => ['*.png', '*.jpg', '*.jpeg', '*.gif', '*.webp', '*.svg', '*.bmp', '*.avif'],
                'markdown' => ['*.md', '*.markdown', '*.txt'],
                'grafida'  => ['*.grafida'],
                default    => [],
            };

            $path = $this->dialog->selectFile(filter: $filter);

            if ($path === null || $path === '') {
                return Json::ok(['cancelled' => true]);
            }
        }

        $raw = @file_get_contents($path);

        if ($raw === false) {
            return Json::error('Could not read the selected file', 400);
        }

        return Json::ok([
            'name'       => basename($path),
            'mime'       => self::mimeForPath($path),
            'dataBase64' => base64_encode($raw),
        ]);
    }

    /**
     * Open a native OS directory-picker (Boson has no "Save As" file dialog,
     * so exporting a draft asks for a destination folder instead and the
     * filename is derived from the draft).
     *
     * @param array<string, mixed> $body
     */
    public function selectDirectory(array $body): Response
    {
        if ($this->dialog === null) {
            return Json::error('Native file dialog is unavailable', 503);
        }

        $path = $this->dialog->selectDirectory();

        if ($path === null || $path === '') {
            return Json::ok(['cancelled' => true]);
        }

        return Json::ok(['path' => $path]);
    }

    /** Best-effort MIME type from a file extension (fileinfo is not bundled). */
    private static function mimeForPath(string $path): string
    {
        return match (strtolower(pathinfo($path, \PATHINFO_EXTENSION))) {
            'png'           => 'image/png',
            'jpg', 'jpeg'   => 'image/jpeg',
            'gif'           => 'image/gif',
            'webp'          => 'image/webp',
            'svg'           => 'image/svg+xml',
            'bmp'           => 'image/bmp',
            'avif'          => 'image/avif',
            'md', 'markdown' => 'text/markdown',
            'txt'           => 'text/plain',
            'grafida'       => 'application/json',
            default         => 'application/octet-stream',
        };
    }

    /**
     * Convert imported Markdown to article HTML.
     *
     * The source is normalised before it is parsed, not after: a Markdown file
     * is very often what an AI tool was asked to write, and cleaning it up first
     * also converts more faithfully. CommonMark wants an *ordinary* space after
     * a `-` or a `#`, so a document carrying no-break spaces parses its lists as
     * paragraphs and its headings as body text. {@see ContentNormaliser}.
     *
     * @param array<string, mixed> $body
     */
    public function convertMarkdown(array $body): Response
    {
        $markdown = $this->normaliser->apply($this->str($body, 'markdown'));

        return Json::ok(['html' => $this->markdown->toHtml($markdown)]);
    }

    /** @param array<string, mixed> $body */
    public function setLanguage(array $body): Response
    {
        $this->language->setOverride($this->str($body, 'tag', LanguageService::AUTO));

        return Json::ok([
            'language' => $this->language->currentTag(),
            'strings'  => $this->language->strings(UiStrings::KEYS),
        ]);
    }

    /** @param array<string, mixed> $body */
    public function setDisplayMode(array $body): Response
    {
        $mode = $this->displayMode->set($this->str($body, 'mode', DisplayModeService::AUTO));

        return Json::ok(['displayMode' => $mode]);
    }

    /** @param array<string, mixed> $body */
    public function setSlashTools(array $body): Response
    {
        $enabled = $this->slashTools->set($this->bool($body, 'enabled', true));

        return Json::ok(['slashTools' => $enabled]);
    }

    /** @param array<string, mixed> $body */
    public function setSpellCheck(array $body): Response
    {
        $enabled = $this->spellCheck->set($this->bool($body, 'enabled', true));

        return Json::ok(['spellCheck' => $enabled]);
    }

    /** @param array<string, mixed> $body */
    public function setAutoCloseTags(array $body): Response
    {
        $mode = $this->autoCloseTags->set($this->str($body, 'mode', AutoCloseTagsService::FULL));

        return Json::ok(['autoCloseTags' => $mode]);
    }

    /** @param array<string, mixed> $body */
    public function setContentNormalisation(array $body): Response
    {
        $mode = $this->normalisation->set($this->str($body, 'mode', ContentNormalisationService::FULL));

        return Json::ok(['contentNormalisation' => $mode]);
    }

    /**
     * Remembers the site the user just selected, so it is re-selected on the
     * next launch. A zero/absent id clears the stored preference.
     *
     * Also clears the Request Log: the log's requests belong to whichever
     * site was active when they were made, so switching sites starts a fresh
     * buffer rather than mixing two sites' exchanges together.
     *
     * @param array<string, mixed> $body
     */
    public function setLastSite(array $body): Response
    {
        $id = $this->int($body, 'siteId');
        $this->lastSite->set($id > 0 ? $id : null);
        $this->requestLog->clear();

        return Json::ok(['lastSiteId' => $id > 0 ? $id : null]);
    }

    /** Re-probes the OS light/dark preference so "auto" can follow it at runtime. */
    public function systemTheme(): Response
    {
        return Json::ok(['systemPrefersDark' => $this->displayMode->systemPrefersDark()]);
    }

    /**
     * Reports whether a newer version is available (refreshing the 12-hour cache
     * from the CDN if needed). Called asynchronously by the SPA at start-up.
     */
    public function updateStatus(): Response
    {
        return Json::ok($this->updates->status());
    }

    public function storageInfo(): Response
    {
        return Json::ok($this->storage->info());
    }

    public function openStorageFolder(): Response
    {
        $this->storage->openContainingFolder();

        return Json::ok();
    }

    public function resetStorage(): Response
    {
        $this->storage->reset();

        return Json::ok();
    }

    /**
     * Toggles the Request Log (Debug) setting. Turning it off also empties
     * the buffer immediately — an implicit "clear" the user would otherwise
     * have to trigger separately, and the honest thing to do since a
     * disabled log should show nothing next time it is enabled.
     *
     * @param array<string, mixed> $body
     */
    public function setRequestLog(array $body): Response
    {
        $enabled = $this->requestLogService->set($this->bool($body, 'enabled', false));

        if (!$enabled) {
            $this->requestLog->clear();
        }

        return Json::ok(['requestLog' => $enabled]);
    }

    /**
     * Updates either or both of the site-metadata cache preferences (gh-42
     * round 2). Each field is applied only when present in the body, so the
     * SPA can send one without clobbering the other — the two selectors in
     * Settings fire independent `change` events. Responds with the
     * **effective** (clamped) values rather than echoing the request, which is
     * what lets the SPA see a server-side clamp happen.
     *
     * @param array<string, mixed> $body
     */
    public function setMetadataCache(array $body): Response
    {
        if (\array_key_exists('resetOnStart', $body)) {
            $this->metadataCache->setResetOnStart($this->bool($body, 'resetOnStart'));
        }

        if (\array_key_exists('ttlMinutes', $body)) {
            $this->metadataCache->setTtlMinutes($this->int($body, 'ttlMinutes', MetadataCacheService::DEFAULT_TTL_MINUTES));
        }

        return Json::ok([
            'metadataResetOnStart' => $this->metadataCache->resetOnStart(),
            'metadataCacheTtl'     => $this->metadataCache->ttlMinutes(),
        ]);
    }

    /** The Request Log screen's data: whether it is enabled and every entry currently stored, newest first. */
    public function requestLog(): Response
    {
        return Json::ok([
            'enabled' => $this->requestLogService->current(),
            'entries' => array_map(static fn (RequestRecord $r): array => $r->toArray(), $this->requestLog->entries()),
        ]);
    }

    public function clearRequestLog(): Response
    {
        $this->requestLog->clear();

        return Json::ok();
    }

    /**
     * Writes the current Request Log to a JSON file in the given directory —
     * the same "ask for a destination folder" pattern as
     * {@see \Grafida\Http\Controller\DraftController::exportDraft()}, since
     * Boson has no native Save-As dialog. Redaction is automatic:
     * {@see RequestRecord::toArray()} is the only way a record is serialised
     * and it always redacts, so there is no separate export-specific
     * redaction path to keep in sync.
     *
     * @param array<string, mixed> $body
     */
    public function exportRequestLog(array $body): Response
    {
        $directory = $this->str($body, 'directory');

        if ($directory === '' || !is_dir($directory) || !is_writable($directory)) {
            return Json::error('A valid, writable destination folder is required.', 400);
        }

        $payload = [
            'app'        => App::NAME . ' ' . App::VERSION,
            'exportedAt' => gmdate('Y-m-d H:i:s'),
            'entries'    => array_map(static fn (RequestRecord $r): array => $r->toArray(), $this->requestLog->entries()),
        ];

        $json = json_encode($payload, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);

        if ($json === false) {
            return Json::error('Could not encode the request log', 500);
        }

        $filename = 'grafida-request-log-' . gmdate('Ymd-His') . '.json';
        $path     = rtrim($directory, '/\\') . \DIRECTORY_SEPARATOR . $filename;

        if (@file_put_contents($path, $json) === false) {
            return Json::error('Could not write the export file', 500);
        }

        return Json::ok(['path' => $path, 'filename' => $filename]);
    }
}
