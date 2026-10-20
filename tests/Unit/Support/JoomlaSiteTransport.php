<?php

/**
 * Grafida — Joomla content editing, untethered.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Tests\Unit\Support;

use Grafida\Http\HttpResponse;
use Grafida\Http\Transport;

/**
 * A role-aware stand-in for a Joomla site's Web Services API.
 *
 * {@see FakeTransport} answers whatever URL it is told to, so every test built
 * on it silently plays a Super User: nothing ever says "no". This one is keyed
 * on the *route* and starts from a profile of what the token's user may do, so
 * a test can say "this is an Author" and get the answers such a token really
 * gets.
 *
 * ⚠️ The {@see self::AUTHOR} profile denies only what the repository documents
 * as needing more than an author has: `GET v1/config/application`
 * (`core.admin`) and `GET v1/templates/styles/site` (`core.manage` on
 * com_templates). Everything else an author's rights depend on — creating
 * tags, uploading media, editing another user's article — varies with the
 * site's own permission set, so a test that wants it denied says so with
 * {@see self::deny()} instead of this class guessing at Joomla's defaults.
 */
final class JoomlaSiteTransport implements Transport
{
    public const SUPER_USER = 'super-user';
    public const AUTHOR     = 'author';

    private const JSON_API = ['Content-Type' => 'application/vnd.api+json'];

    /** @var array<string, HttpResponse> "METHOD route" => response */
    private array $routes = [];

    /** @var list<array{method: string, url: string, headers: array<string,string>, body: ?string}> */
    public array $requests = [];

    public function __construct(
        string $role = self::AUTHOR,
        private readonly string $apiBase = 'https://example.com/index.php/api',
    ) {
        $this->routes = [
            'GET users/levels'              => self::collection([[1, ['title' => 'Public']]]),
            'GET content/categories'        => self::collection([[2, ['title' => 'News']]]),
            'GET tags'                      => self::collection([[5, ['title' => 'Existing']]]),
            'GET languages/content'         => self::collection([[1, ['lang_code' => 'en-GB']]]),
            'GET fields/content/articles'   => self::collection([[7, ['name' => 'colour', 'type' => 'text']]]),
            'GET fields/content/articles/7' => self::resource(7, ['name' => 'colour', 'assigned_cat_ids' => [2]]),
            'GET media/adapters'            => self::collection([[1, ['provider_id' => 'local', 'name' => 'images', 'path' => 'local-images:/']]]),
            'POST content/articles'         => self::resource(42, ['title' => 'Author article'], 201, 'articles'),
            'POST tags'                     => self::resource(99, ['title' => 'New tag'], 201, 'tags'),
            'POST media/files'              => self::resource(1, [
                'path'  => 'local-images:/grafida/x.png',
                'url'   => 'https://example.com/images/grafida/x.png',
                'width' => 1,
                'height' => 1,
            ], 201, 'media'),
        ];

        if ($role === self::SUPER_USER) {
            $this->routes['GET config/application']     = self::collection([[1, ['unicodeslugs' => 1]]]);
            $this->routes['GET templates/styles/site'] = self::collection([[1, ['template' => 'cassiopeia', 'home' => '1']]]);

            return;
        }

        $this->deny('GET', 'config/application');
        $this->deny('GET', 'templates/styles/site');
    }

    /** Answers `$method $route` (route is relative to `{apiBase}/v1/`) with a fixed response. */
    public function serve(string $method, string $route, HttpResponse $response): self
    {
        $this->routes[strtoupper($method) . ' ' . $route] = $response;

        return $this;
    }

    /** Answers `$method $route` the way Joomla answers a user without the permission. */
    public function deny(string $method, string $route): self
    {
        return $this->serve($method, $route, self::forbidden());
    }

    public static function forbidden(): HttpResponse
    {
        return new HttpResponse(
            403,
            json_encode(['errors' => [['title' => 'Forbidden', 'code' => 403]]]),
            self::JSON_API,
        );
    }

    /**
     * @param list<array{0: int, 1: array<string, mixed>}> $items [id, attributes] pairs
     */
    public static function collection(array $items): HttpResponse
    {
        $data = [];

        foreach ($items as [$id, $attributes]) {
            $data[] = ['type' => 'x', 'id' => (string) $id, 'attributes' => $attributes];
        }

        return new HttpResponse(200, json_encode(['data' => $data, 'meta' => ['total-pages' => 1]]), self::JSON_API);
    }

    /** @param array<string, mixed> $attributes */
    public static function resource(int $id, array $attributes, int $status = 200, string $type = 'x'): HttpResponse
    {
        return new HttpResponse(
            $status,
            json_encode(['data' => ['type' => $type, 'id' => (string) $id, 'attributes' => $attributes]]),
            self::JSON_API,
        );
    }

    /** The requests that reached `$method $route`. */
    public function requestsTo(string $method, string $route): int
    {
        $count = 0;

        foreach ($this->requests as $request) {
            if ($request['method'] === strtoupper($method) && $this->routeOf($request['url']) === $route) {
                $count++;
            }
        }

        return $count;
    }

    public function request(string $method, string $url, array $headers = [], ?string $body = null): HttpResponse
    {
        $this->requests[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body];

        $route = $this->routeOf($url);

        if ($route === null) {
            return new HttpResponse(404, '<html>Not Found</html>', ['Content-Type' => 'text/html']);
        }

        return $this->routes[strtoupper($method) . ' ' . $route]
            ?? new HttpResponse(404, json_encode(['errors' => [['title' => 'Resource not found', 'code' => 404]]]), self::JSON_API);
    }

    /** The route part of a URL under `{apiBase}/v1/`, query string removed; null for any other URL. */
    private function routeOf(string $url): ?string
    {
        $prefix = $this->apiBase . '/v1/';

        if (!str_starts_with($url, $prefix)) {
            return null;
        }

        return explode('?', substr($url, strlen($prefix)), 2)[0];
    }
}
