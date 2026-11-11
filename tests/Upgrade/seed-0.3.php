<?php

/**
 * Grafida — Joomla content editing, untethered.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

/*
 * Seeds a Grafida **0.3** (Boson) install with the data the upgrade test checks after 0.4 is
 * installed over it (gh-92). It runs 0.3's own code: build-seed.php packs it into a PHAR together
 * with a Grafida 0.3 checkout, and the PHAR runs on 0.3's own PHP (its sibling-phar SFX stub, renamed
 * beside it), inside the desktop session of a machine where 0.3 was installed and launched once.
 *
 * Everything goes through 0.3's kernel, as its SPA would, except two things that need a network or
 * Boson's dialog: the site (whose creation tests the connection) is written by 0.3's repository and
 * 0.3's own token persistence, and the `.grafida` export is asked for with a plain directory, which
 * 0.3's export route accepts. The images are inline data: URIs that 0.3's InlineImageExtractor turns
 * into media blobs and `boson://app/api/media/{id}/raw?rev=…` references, as it did for every draft
 * saved before gh-36; no URL is typed by hand.
 *
 *   seed --ai-endpoint=http://192.168.64.1:8766/v1 --export-dir=DIR --manifest=FILE
 *
 * The manifest (JSON) lists what was written, the test secrets included, so the after-checks can
 * compare. The secrets are throwaway values made here; the manifest stays in the test guest.
 * This file is test tooling: it is not shipped, and PHPStan does not read it (0.3's classes).
 */

use Boson\Component\Http\Request;
use Boson\Component\Http\Static\StaticProviderInterface;
use Boson\Contracts\Http\RequestInterface;
use Boson\Contracts\Http\ResponseInterface;
use Grafida\Application\ContainerFactory;
use Grafida\Application\Kernel;
use Grafida\Site\SiteRepository;
use Grafida\Site\SiteService;
use Grafida\Support\App;
use Grafida\Support\Paths;

error_reporting(E_ALL & ~E_DEPRECATED);

// Inside the PHAR this file is tests/Upgrade/seed-0.3.php of a 0.3 tree.
require \dirname(__DIR__, 2) . '/vendor/autoload.php';

$opts = getopt('', ['ai-endpoint:', 'export-dir:', 'manifest:']);

foreach (['ai-endpoint', 'export-dir', 'manifest'] as $required) {
    if (!isset($opts[$required]) || !\is_string($opts[$required]) || $opts[$required] === '') {
        fwrite(STDERR, "usage: seed --ai-endpoint=URL --export-dir=DIR --manifest=FILE\n");
        exit(64);
    }
}

$fail = static function (string $message): never {
    fwrite(STDERR, 'seed: ' . $message . "\n");
    exit(1);
};

if (App::VERSION !== '0.3') {
    $fail('this PHAR carries Grafida ' . App::VERSION . ', not 0.3');
}

$container = ContainerFactory::create([
    // Every other parameter keeps 0.3's production default: the real data folder and database,
    // and the OS secret store (the Keychain, Secret Service or DPAPI).
    'static.provider' => new class implements StaticProviderInterface {
        public function findFileByRequest(RequestInterface $request): ?ResponseInterface
        {
            return null;
        }
    },
]);
$kernel = $container->get(Kernel::class);

/** @return array<string, mixed> */
$call = static function (string $method, string $path, ?array $body = null) use ($kernel, $fail): array {
    $response = $kernel->handle(new Request($method, 'boson://app' . $path, [], $body === null ? '' : json_encode($body, JSON_THROW_ON_ERROR)));
    $json     = json_decode((string) $response->body, true);

    if ((int) (string) $response->status >= 300 || !\is_array($json) || ($json['ok'] ?? false) !== true) {
        $fail("$method $path answered " . (int) (string) $response->status . ': ' . (string) $response->body);
    }

    return \is_array($json['data'] ?? null) ? $json['data'] : ['value' => $json['data'] ?? null];
};

$manifest = [
    'seededBy'  => 'Grafida ' . App::VERSION . ' (tests/Upgrade/seed-0.3.php)',
    'php'       => PHP_VERSION . ' ' . php_uname('m'),
    'binary'    => PHP_BINARY,
    'dataDir'   => Paths::dataDir(),
    'database'  => Paths::databaseFile(),
    'seededAt'  => gmdate('c'),
];

// 1. The site: 0.3's repository and 0.3's own token persistence (the connection test needs a site).
$siteService = $container->get(SiteService::class);
$siteToken   = 'test-joomla-token-' . bin2hex(random_bytes(12));
$persist     = Closure::bind(fn (string $token): array => $this->persistToken($token, false), $siteService, SiteService::class);
[$secretRef] = $persist($siteToken);
$siteId      = $container->get(SiteRepository::class)->insert(
    title: 'Upgrade Test Site',
    baseUrl: 'https://upgrade-test.example',
    apiBase: 'https://upgrade-test.example/api/index.php/v1',
    secretRef: $secretRef,
    insecureToken: null,
    editorCssUrl: null,
    mediaAdapter: 'local-images',
    mediaFolder: 'grafida-upgrade',
    unicodeAliases: 'yes',
);
$manifest['site'] = ['id' => $siteId, 'title' => 'Upgrade Test Site', 'secretRef' => $secretRef, 'token' => $siteToken,
    'mediaAdapter' => 'local-images', 'mediaFolder' => 'grafida-upgrade', 'unicodeAliases' => 'yes'];

// 2. An AI service on the LAN (the endpoint the guest reaches the host's test server at).
$aiKey = 'test-ai-key-' . bin2hex(random_bytes(12));
$ai    = $call('POST', '/api/ai/services', [
    'name' => 'Upgrade Test LLM', 'provider' => 'custom', 'endpoint' => $opts['ai-endpoint'],
    'model' => 'fake-model', 'key' => $aiKey, 'allowInsecure' => false,
]);
$manifest['ai'] = ['id' => $ai['id'] ?? null, 'name' => 'Upgrade Test LLM', 'endpoint' => $opts['ai-endpoint'], 'key' => $aiKey,
    'secretRef' => 'grafida.ai_service.' . ($ai['id'] ?? '')];

// 3. Two drafts with inline images, then a read of each, which turns them into media blobs and
//    boson:// references (0.3's InlineImageExtractor) and saves the result.
$red  = 'iVBORw0KGgoAAAANSUhEUgAAADAAAAAgCAIAAADbtmxLAAAANklEQVR4nO3OQQ0AAAgEIOPYP49hbOH5YCMANd2vVHwgJCQklB4ICQkJpQdCQkJC6YGQkNCxBYZgCGp6c8mtAAAAAElFTkSuQmCC';
$blue = 'iVBORw0KGgoAAAANSUhEUgAAACAAAAAwCAIAAAD/zu84AAAAL0lEQVR4nO3NQQkAAAgEsItjJrMbxhQ+hMH+S/WcikAgEAgEAoFAIBAIBAKB4FOwGSc0eeo8BawAAAAASUVORK5CYII=';
$manifest['drafts'] = [];
$manifest['images'] = ['red' => hash('sha256', (string) base64_decode($red, true)), 'blue' => hash('sha256', (string) base64_decode($blue, true))];

foreach ([
    ['Upgrade test: two pictures', "<p>Before the pictures, Ελληνικά and ü.</p>\n<p><img src=\"data:image/png;base64,$red\" alt=\"red\"></p>\n<hr class=\"readmore\">\n<p><img src=\"data:image/png;base64,$blue\" alt=\"blue\"></p>"],
    ['Upgrade test: no pictures', '<p>A plain draft.</p>'],
] as [$title, $html]) {
    $saved = $call('POST', "/api/sites/$siteId/drafts", ['title' => $title, 'alias' => '', 'html' => $html, 'state' => 0, 'language' => '*']);
    $id    = (int) ($saved['id'] ?? 0);
    $read  = $call('GET', "/api/drafts/$id");
    preg_match_all('#boson://app/api/media/(\d+)/raw\?rev=[^"\']+#', (string) ($read['html'] ?? ''), $m);
    $manifest['drafts'][] = ['id' => $id, 'title' => $title, 'html' => $read['html'] ?? null, 'mediaUrls' => $m[0], 'mediaIds' => array_map('intval', $m[1])];
}

if (\count($manifest['drafts'][0]['mediaIds']) !== 2) {
    $fail('0.3 did not turn the two inline images into media references: ' . json_encode($manifest['drafts'][0]));
}

foreach ($manifest['drafts'][0]['mediaIds'] as $mediaId) {
    $info = $call('GET', "/api/media/$mediaId");
    $manifest['media'][] = $info + ['id' => $mediaId];
}

// 4. A .grafida export of the draft with pictures.
if (!is_dir($opts['export-dir']) && !mkdir($opts['export-dir'], 0o700, true)) {
    $fail('cannot create ' . $opts['export-dir']);
}

$export = $call('POST', '/api/drafts/' . $manifest['drafts'][0]['id'] . '/export', ['directory' => $opts['export-dir']]);
$manifest['export'] = $export;

// 5. Preferences that differ from every default.
$preferences = [
    ['/api/settings/language', ['tag' => 'de-DE']],
    ['/api/settings/display-mode', ['mode' => 'dark']],
    ['/api/settings/slash-tools', ['enabled' => false]],
    ['/api/settings/spell-check', ['enabled' => false]],
    ['/api/settings/auto-close-tags', ['mode' => 'closing']],
    ['/api/settings/content-normalisation', ['mode' => 'invisible']],
    ['/api/settings/last-site', ['siteId' => $siteId]],
];

foreach ($preferences as [$path, $body]) {
    $call('POST', $path, $body);
}

$manifest['preferences'] = ['language' => 'de-DE', 'displayMode' => 'dark', 'slashTools' => false, 'spellCheck' => false,
    'autoCloseTags' => 'closing', 'contentNormalisation' => 'invisible', 'lastSiteId' => $siteId];

// What 0.3 itself now reports, for the record.
$manifest['bootstrap'] = $call('GET', '/api/bootstrap');
unset($manifest['bootstrap']['strings']);

file_put_contents($opts['manifest'], json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n");
echo 'seeded: site ', $siteId, ', AI service ', $manifest['ai']['id'], ', drafts ', implode(' ', array_column($manifest['drafts'], 'id')),
    ', media ', implode(' ', $manifest['drafts'][0]['mediaIds']), ', export ', json_encode($export), "\n";
