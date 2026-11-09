<?php

/**
 * Grafida — Joomla content editing, untethered.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Http\Controller;

use Grafida\Shell\Response;
use Grafida\Ai\AiChat;
use Grafida\Ai\AiChatRepository;
use Grafida\Ai\AiMessage;
use Grafida\Ai\AiProxy;
use Grafida\Ai\AiProxyException;
use Grafida\Ai\AiRenderer;
use Grafida\Http\Json;
use Grafida\Http\RouteContext;
use Grafida\Http\Router;

/**
 * Handles `/api/ai/chats*`, `GET /api/drafts/{id}/chats`, `POST /api/ai/proxy`
 * and `POST /api/ai/render` — saved AI chat CRUD, the non-streaming proxy
 * fallback, and rendering an AI reply to sanitised HTML.
 */
final class AiChatController extends Controller
{
    public function __construct(
        private readonly AiProxy $aiProxy,
        private readonly AiRenderer $aiRenderer,
        private readonly AiChatRepository $aiChats,
    ) {}

    public function registerRoutes(Router $router): void
    {
        $router->add('POST', '/api/ai/proxy', fn (RouteContext $ctx): Response => $this->aiProxy($ctx->body()));
        $router->add('POST', '/api/ai/render', fn (RouteContext $ctx): Response => $this->renderAiReply($ctx->body()));
        $router->add('POST', '/api/ai/chats', fn (RouteContext $ctx): Response => $this->createAiChat($ctx->body()));
        $router->add('GET', '/api/drafts/{id}/chats', fn (RouteContext $ctx): Response => $this->listDraftChats($ctx->int('id')));
        $router->add('GET', '/api/ai/chats/{id}', fn (RouteContext $ctx): Response => $this->getAiChat($ctx->int('id')));
        $router->add('PATCH', '/api/ai/chats/{id}', fn (RouteContext $ctx): Response => $this->updateAiChat($ctx->int('id'), $ctx->body()));
        $router->add('DELETE', '/api/ai/chats/{id}', fn (RouteContext $ctx): Response => $this->deleteAiChat($ctx->int('id')));
    }

    /**
     * Validates and forwards a non-streaming AI provider request.
     *
     * The body must supply `{serviceId, url, method, headers, body}`.  The
     * proxy validates that the target URL's host matches the configured
     * service endpoint — it never injects credentials (the JS side does that).
     *
     * @param array<string, mixed> $body
     */
    public function aiProxy(array $body): Response
    {
        $serviceIdRaw = $body['serviceId'] ?? null;

        if (!is_numeric($serviceIdRaw)) {
            return Json::error('A numeric serviceId is required.', 400);
        }

        $url     = $this->str($body, 'url');
        $method  = $this->str($body, 'method', 'POST');
        $rawBody = $this->str($body, 'body');

        $headersRaw = $body['headers'] ?? null;
        /** @var array<string, string> $headers */
        $headers = [];

        if (is_array($headersRaw)) {
            foreach ($headersRaw as $k => $v) {
                if (is_string($k) && is_string($v)) {
                    $headers[$k] = $v;
                }
            }
        }

        if ($url === '') {
            return Json::error('A target URL is required.', 400);
        }

        try {
            $result = $this->aiProxy->forward((int) $serviceIdRaw, $url, $method, $headers, $rawBody);
        } catch (AiProxyException $e) {
            return Json::error($e->getMessage(), $e->httpStatus);
        }

        return Json::ok($result);
    }

    /**
     * Render an AI assistant reply to sanitised HTML for the chat panel.
     *
     * The reply is untrusted model output (HTML, or Markdown for the Generate
     * tool); {@see AiRenderer} converts Markdown via CommonMark when needed and
     * sanitises the result with Symfony's HtmlSanitizer before it is shown.
     *
     * @param array<string, mixed> $body
     */
    public function renderAiReply(array $body): Response
    {
        $content = $this->str($body, 'content');
        $format  = $this->str($body, 'format', 'auto');

        return Json::ok(['html' => $this->aiRenderer->render($content, $format)]);
    }

    /** Lists saved chats (metadata only) for a draft, ordered newest first. */
    public function listDraftChats(int $draftId): Response
    {
        $chats = $this->aiChats->forDraft($draftId);

        return Json::ok(array_map(static fn (AiChat $c): array => $c->toArray(), $chats));
    }

    /** @param array<string, mixed> $body */
    public function createAiChat(array $body): Response
    {
        $draftIdRaw = $body['draftId'] ?? null;

        if (!is_numeric($draftIdRaw)) {
            return Json::error('A numeric draftId is required.', 400);
        }

        $draftId = (int) $draftIdRaw;

        $serviceIdRaw = $body['serviceId'] ?? null;
        $serviceId    = is_numeric($serviceIdRaw) ? (int) $serviceIdRaw : null;

        $previousResponseIdRaw = $body['previousResponseId'] ?? null;
        $previousResponseId    = is_string($previousResponseIdRaw) ? $previousResponseIdRaw : null;

        $lastResponseAtRaw = $body['lastResponseAt'] ?? null;
        $lastResponseAt    = is_string($lastResponseAtRaw) ? $lastResponseAtRaw : null;

        $messages = $this->parseAiMessages($body, null);

        $chat = new AiChat(
            id: null,
            draftId: $draftId,
            serviceId: $serviceId,
            title: $this->str($body, 'title'),
            messages: $messages,
            previousResponseId: $previousResponseId,
            lastResponseAt: $lastResponseAt,
        );

        $id      = $this->aiChats->create($chat);
        $created = $this->aiChats->find($id);

        return Json::ok($created?->toArray(), 201);
    }

    /** Returns a single chat with all its messages. */
    public function getAiChat(int $id): Response
    {
        $chat = $this->aiChats->find($id);

        if ($chat === null) {
            return Json::error('Chat not found', 404);
        }

        return Json::ok($chat->toArray());
    }

    /**
     * Renames a chat, replaces its messages, and/or updates its response-id chain.
     *
     * Accepts `{title?, messages?, serviceId?, previousResponseId?, lastResponseAt?}`. An
     * empty/absent `title` leaves the existing title unchanged; a non-empty `title` renames
     * the chat. A present `messages` key (even an empty array) replaces the stored transcript.
     * A present `previousResponseId` or `lastResponseAt` key updates the response-id chain
     * together with `serviceId` (falling back to the chat's existing serviceId when the body
     * omits it) so the chain and its owning service are always written atomically.
     *
     * @param array<string, mixed> $body
     */
    public function updateAiChat(int $id, array $body): Response
    {
        $chat = $this->aiChats->find($id);

        if ($chat === null) {
            return Json::error('Chat not found', 404);
        }

        if (array_key_exists('title', $body)) {
            $title = $this->str($body, 'title');
            if ($title !== '') {
                $this->aiChats->rename($id, $title);
            }
        }

        if (array_key_exists('messages', $body)) {
            $messages = $this->parseAiMessages($body, $id);
            $this->aiChats->replaceMessages($id, $messages);
        }

        if (array_key_exists('previousResponseId', $body) || array_key_exists('lastResponseAt', $body)) {
            $serviceIdRaw = $body['serviceId'] ?? null;
            $serviceId    = is_numeric($serviceIdRaw) ? (int) $serviceIdRaw : $chat->serviceId;

            $previousResponseIdRaw = $body['previousResponseId'] ?? null;
            $previousResponseId    = is_string($previousResponseIdRaw) ? $previousResponseIdRaw : null;

            $lastResponseAtRaw = $body['lastResponseAt'] ?? null;
            $lastResponseAt    = is_string($lastResponseAtRaw) ? $lastResponseAtRaw : null;

            $this->aiChats->setResponseChain($id, $serviceId, $previousResponseId, $lastResponseAt);
        }

        $updated = $this->aiChats->find($id);

        return Json::ok($updated?->toArray());
    }

    /** Deletes a chat and, via ON DELETE CASCADE, all its messages. */
    public function deleteAiChat(int $id): Response
    {
        if ($this->aiChats->find($id) === null) {
            return Json::error('Chat not found', 404);
        }

        $this->aiChats->delete($id);

        return Json::ok();
    }

    /**
     * Parses the `messages` array from a request body into a list of AiMessage objects.
     *
     * @param array<string, mixed> $body
     * @param int|null             $chatId  Pre-assigned chat id (for updates) or null (for creates).
     *
     * @return list<AiMessage>
     */
    private function parseAiMessages(array $body, ?int $chatId): array
    {
        $raw = $body['messages'] ?? null;

        if (!is_array($raw)) {
            return [];
        }

        $messages = [];
        $i        = 0;

        foreach ($raw as $m) {
            if (!is_array($m)) {
                continue;
            }

            $role    = is_string($m['role'] ?? null) ? $m['role'] : '';
            $content = is_string($m['content'] ?? null) ? $m['content'] : '';

            if ($role === '' || $content === '') {
                continue;
            }

            $toolKeyRaw = $m['toolKey'] ?? null;
            $toolKey    = is_string($toolKeyRaw) && $toolKeyRaw !== '' ? $toolKeyRaw : null;
            $sortOrder  = isset($m['sortOrder']) && is_numeric($m['sortOrder']) ? (int) $m['sortOrder'] : $i;

            $messages[] = new AiMessage(
                id: null,
                chatId: $chatId,
                role: $role,
                content: $content,
                toolKey: $toolKey,
                sortOrder: $sortOrder,
            );

            ++$i;
        }

        return $messages;
    }
}
