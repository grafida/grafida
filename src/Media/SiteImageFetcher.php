<?php

/**
 * Grafida — Joomla content editing, untethered.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Media;

use Grafida\Http\HttpException;
use Grafida\Http\Transport;
use Grafida\Site\Site;

/**
 * Fetches an already-published article image so it can be handed to a
 * multimodal AI model.
 *
 * An article that has been published once carries its images as ordinary URLs
 * (`images/foo.jpg`, or an absolute URL on the site). The SPA cannot fetch
 * those itself — a `fetch()` from the webview's `shell://app` origin hits the
 * same CORS / macOS-ATS wall the AI transport does — so the bytes are pulled
 * here, over PHP's HTTP stack, and handed back as a data: URI.
 *
 * Security model
 * --------------
 * Mirrors `Ai\AiProxy`: the resolved URL's host must equal the site's own host,
 * so this cannot be turned into a general-purpose fetcher for arbitrary hosts.
 * An image hosted elsewhere (a CDN, a hotlinked third party) is therefore
 * refused rather than fetched — the caller skips it.
 */
final class SiteImageFetcher
{
    /**
     * Cap on the bytes we will turn into a data: URI. A base64 payload is ~4/3
     * of this, and it is embedded in a chat request, so an enormous original is
     * refused rather than sent.
     */
    private const MAX_BYTES = 12 * 1024 * 1024;

    /** Image MIME types the vision APIs actually accept. */
    private const ALLOWED_MIME = ['image/png', 'image/jpeg', 'image/gif', 'image/webp'];

    public function __construct(
        private readonly Transport $http,
    ) {}

    /**
     * Resolves `$url` against the site, fetches it and returns a data: URI.
     *
     * @return array{dataUri: string, mime: string}
     *
     * @throws SiteImageException When the URL is off-site, unfetchable or not a usable image.
     */
    public function fetch(Site $site, string $url): array
    {
        $absolute = $this->resolve($site->baseUrl, $url);

        try {
            $response = $this->http->request('GET', $absolute);
        } catch (HttpException $e) {
            throw new SiteImageException('Could not fetch the image: ' . $e->getMessage(), 502);
        }

        if ($response->status !== 200) {
            throw new SiteImageException('The image request returned HTTP ' . $response->status . '.', 404);
        }

        $body = $response->body;

        if ($body === '') {
            throw new SiteImageException('The image is empty.', 404);
        }

        if (\strlen($body) > self::MAX_BYTES) {
            throw new SiteImageException('The image is too large to send to a model.', 413);
        }

        $mime = $this->detectMime($body);

        if ($mime === null) {
            throw new SiteImageException('The URL did not return a supported image.', 415);
        }

        return [
            'dataUri' => 'data:' . $mime . ';base64,' . base64_encode($body),
            'mime'    => $mime,
        ];
    }

    /**
     * Turns an article-image `src` into an absolute URL on the site, rejecting
     * anything that does not land on the site's own host.
     *
     * @throws SiteImageException
     */
    private function resolve(string $baseUrl, string $url): string
    {
        $url = trim($url);

        if ($url === '') {
            throw new SiteImageException('An image URL is required.', 400);
        }

        $siteHost = parse_url($baseUrl, \PHP_URL_HOST);

        if (!\is_string($siteHost) || $siteHost === '') {
            throw new SiteImageException('The site has no usable base URL.', 400);
        }

        $scheme = parse_url($url, \PHP_URL_SCHEME);

        // A site-relative src ("images/foo.jpg", "/images/foo.jpg") is resolved
        // against the site root; it is on-site by construction.
        if ($scheme === null || $scheme === false) {
            return rtrim($baseUrl, '/') . '/' . ltrim($url, '/');
        }

        if (!\in_array(strtolower($scheme), ['http', 'https'], true)) {
            throw new SiteImageException('Only http(s) image URLs can be fetched.', 400);
        }

        $host = parse_url($url, \PHP_URL_HOST);

        if (!\is_string($host) || strtolower($host) !== strtolower($siteHost)) {
            throw new SiteImageException(
                'The image is hosted on "' . (\is_string($host) ? $host : '') . '", not on the site itself.',
                403,
            );
        }

        return $url;
    }

    /**
     * Sniffs the MIME type from the bytes themselves rather than trusting the
     * response's Content-Type header, which a misconfigured server may get
     * wrong (and which the vision APIs will then reject).
     */
    private function detectMime(string $body): ?string
    {
        $info = @getimagesizefromstring($body);

        if ($info === false) {
            return null;
        }

        $mime = strtolower($info['mime']);

        return \in_array($mime, self::ALLOWED_MIME, true) ? $mime : null;
    }
}
