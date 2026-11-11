<?php

/**
 * Grafida — Joomla content editing, untethered.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Tests\Pipe;

use Grafida\Application\Kernel;
use Grafida\Shell\Request;
use Grafida\Shell\Response;
use Grafida\Shell\Testing\Fixtures;
use Grafida\Shell\Testing\WorkerProcess;
use Grafida\Shell\Worker;
use Grafida\Support\App;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Grafida's back-end as a stdio worker, over real pipes (gh-84).
 *
 * Two halves:
 *
 * - **`bin/worker.php` itself**: the handshake, a smoke test of the API, static assets and the SPA
 *   shell, and every wire-protocol conformance fixture from grafida/shell, played the way the shell
 *   will play them. It runs against a throwaway data directory, never the user's.
 * - **A differential run of Feature-suite request sequences**: the same container
 *   ({@see PipeApp}) answers each request twice, in process through `Kernel::handle()` and in a
 *   worker process through the pipe, and the two responses must be identical. That checks every
 *   byte the transport could disturb, not just what a Feature assertion happens to look at, and it
 *   needs no edit to the Feature suite itself, whose assertions are the API contract.
 */
final class WorkerPipeTest extends TestCase
{
    private static ?string $home = null;

    /** A throwaway data/config/home directory, so bin/worker.php never touches the user's database. */
    private static function isolatedEnv(): array
    {
        if (self::$home === null) {
            self::$home = sys_get_temp_dir() . '/grafida-pipe-' . bin2hex(random_bytes(6));
            mkdir(self::$home, 0o700, true);
        }

        $env = getenv();

        foreach (['HOME', 'XDG_DATA_HOME', 'XDG_CONFIG_HOME', 'APPDATA', 'LOCALAPPDATA', 'USERPROFILE'] as $name) {
            $env[$name] = self::$home;
        }

        return $env;
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$home === null) {
            return;
        }

        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(self::$home, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($it as $file) {
            \assert($file instanceof \SplFileInfo);
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }

        rmdir(self::$home);
        self::$home = null;
    }

    /**
     * `bin/worker.php` on this PHP, or the packaged worker named by `GRAFIDA_PIPE_WORKER` (gh-86:
     * the SFX stub of an extracted shell tarball), in which case the same handshake, smoke and
     * conformance tests run against what ships. {@see PackagedWorkerTest} has the packaged-only ones.
     */
    private static function realWorker(): WorkerProcess
    {
        $packaged = getenv('GRAFIDA_PIPE_WORKER');

        if (\is_string($packaged) && $packaged !== '') {
            return new WorkerProcess([$packaged], \dirname($packaged), self::isolatedEnv(), 30.0);
        }

        return new WorkerProcess([\PHP_BINARY, \dirname(__DIR__, 2) . '/bin/worker.php'], \dirname(__DIR__, 2), self::isolatedEnv());
    }

    public function testBinWorkerAnnouncesItselfAndServesTheApp(): void
    {
        $worker = self::realWorker();

        self::assertSame(1, $worker->ready->protocol);
        self::assertSame($worker->pid(), $worker->ready->pid);
        self::assertSame(['grafida', App::VERSION], [$worker->ready->app, $worker->ready->version]);

        $bootstrap = $worker->request(new Request('GET', 'boson://app/api/bootstrap'));
        self::assertSame(200, $bootstrap->status);
        self::assertStringContainsString('no-store', (string) $bootstrap->header('cache-control'));
        $json = json_decode($bootstrap->body, true);
        self::assertIsArray($json);
        self::assertTrue($json['ok']);

        $css = $worker->request(new Request('GET', 'boson://app/css/app.css'));
        self::assertSame(200, $css->status);
        self::assertSame('text/css; charset=utf-8', $css->header('content-type'));
        self::assertSame(file_get_contents(\dirname(__DIR__, 2) . '/assets/private/css/app.css'), $css->body);

        $spa = $worker->request(new Request('GET', 'boson://app/articles/42'));
        self::assertSame(200, $spa->status);
        self::assertSame(file_get_contents(\dirname(__DIR__, 2) . '/assets/private/view/index.html'), $spa->body);

        self::assertSame(404, $worker->request(new Request('GET', 'boson://app/api/nope'))->status);

        $worker->closeInput();
        self::assertSame(Worker::EXIT_OK, $worker->wait());
        self::assertStringNotContainsString('[stray-output]', $worker->stderr());
        self::assertStringNotContainsString('[protocol-error]', $worker->stderr());
        // Grafida\Support\Paths: ~/Library/Application Support/Grafida on macOS, $XDG_DATA_HOME/grafida on Linux.
        $data = \PHP_OS_FAMILY === 'Darwin' ? self::$home . '/Library/Application Support/Grafida' : self::$home . '/grafida';
        self::assertFileExists($data . '/grafida.sqlite', 'the worker used the throwaway data dir');
    }

    /**
     * A worker that cannot build the app (gh-92): here its data directory sits under a regular file,
     * so no database can be created. It must never send `ready`, must exit 69, and must open its
     * stderr with FailureReporter's plain sentence and keep it inside the last 40 lines, which is
     * what the shell's alert shows.
     */
    public function testAWorkerThatCannotStartSaysWhyInTheShellsAlertWindow(): void
    {
        $blocker = sys_get_temp_dir() . '/grafida-pipe-blocker-' . bin2hex(random_bytes(6));
        file_put_contents($blocker, 'not a directory');
        $env = getenv();

        foreach (['HOME', 'XDG_DATA_HOME', 'XDG_CONFIG_HOME', 'APPDATA', 'LOCALAPPDATA', 'USERPROFILE'] as $name) {
            $env[$name] = $blocker . '/data';
        }

        $packaged = getenv('GRAFIDA_PIPE_WORKER');
        $command  = \is_string($packaged) && $packaged !== '' ? [$packaged] : [\PHP_BINARY, \dirname(__DIR__, 2) . '/bin/worker.php'];
        $process  = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, \dirname($command[\count($command) - 1]), $env);
        self::assertIsResource($process);
        fclose($pipes[0]);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($process);
        unlink($blocker);

        self::assertSame(69, $code, $stderr);
        self::assertSame('', $stdout, 'no ready frame, nothing at all on the frame stream');
        self::assertStringStartsWith('Grafida could not start.', $stderr);
        self::assertStringContainsString('could not open its database', $stderr);
        $lines = explode("\n", rtrim($stderr));
        self::assertLessThanOrEqual(40, \count($lines), 'the sentence stays inside the alert\'s 40-line tail');
    }

    /**
     * Every conformance fixture against the real worker: it answers each request with its id, exits
     * 0 at EOF or `shutdown`, and stops with 65 on any malformed stream, without waiting for more
     * input except where EOF is the signal.
     */
    #[DataProvider('fixtures')]
    public function testBinWorkerPassesConformanceFixture(string $name): void
    {
        $outcome = Fixtures::workerOutcome(Fixtures::expected($name));
        $run     = self::realWorker()->playFixture($name);

        self::assertSame($outcome['exit'], $run['exit'], "$name: exit status. stderr: " . $run['stderr']);
        self::assertSame($outcome['responses'], array_map(static fn ($r): int => $r->id, $run['responses']), "$name: answered ids");

        if ($outcome['error'] !== null) {
            self::assertStringContainsString('[protocol-error] ' . $outcome['error'], $run['stderr'], $name);
        }

        self::assertStringNotContainsString('[stray-output]', $run['stderr'], $name);
    }

    /** @return iterable<string, array{string}> */
    public static function fixtures(): iterable
    {
        return Fixtures::provider();
    }

    /**
     * Request sequences taken from the Feature suite. `{key}` in a path or body is replaced by
     * `data.key` of the most recent response that had one (a created draft's id, the help home page).
     *
     * @return iterable<string, array{list<array{0: string, 1: string, 2?: string, 3?: array<string, string>}>}>
     */
    public static function scenarios(): iterable
    {
        $png = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=';

        yield 'bootstrap and routing' => [[
            ['GET', '/api/bootstrap'],
            ['GET', '/api/sites'],
            ['GET', '/api/sites/999'],
            ['GET', '/api/nope'],
            ['PUT', '/api/bootstrap'],
            ['GET', '/api/settings/system-theme'],
        ]];

        yield 'settings persist' => [[
            ['POST', '/api/settings/display-mode', '{"mode":"dark"}'],
            ['POST', '/api/settings/slash-tools', '{"enabled":false}'],
            ['POST', '/api/settings/spell-check', '{"enabled":false}'],
            ['POST', '/api/settings/auto-close-tags', '{"mode":"closing"}'],
            ['POST', '/api/settings/auto-close-tags', '{"mode":"bogus"}'],
            ['POST', '/api/settings/content-normalisation', '{"mode":"invisible"}'],
            ['POST', '/api/settings/language', '{"tag":"el-GR"}'],
            ['POST', '/api/settings/metadata-cache', '{"ttlMinutes":360}'],
            ['POST', '/api/settings/last-site', '{"siteId":1}'],
            ['GET', '/api/bootstrap'],
        ]];

        yield 'help, including a binary image' => [[
            ['GET', '/api/help'],
            ['GET', '/api/help/page/{home}'],
            ['GET', '/api/help/page/No-Such-Page'],
            ['GET', '/api/help/image/ai-services.png'],
            ['GET', '/api/help/image/absent.png'],
            ['POST', '/api/help'],
        ]];

        yield 'markdown import strips invisible characters' => [[
            ['POST', '/api/markdown', json_encode(['markdown' => "# Καλημέρα\u{200B}\n\nText\u{00AD}with a soft hyphen"], \JSON_THROW_ON_ERROR)],
        ]];

        yield 'draft lifecycle' => [[
            ['POST', '/api/sites/1/drafts', json_encode(['title' => 'Ελληνικά', 'alias' => 'hello', 'html' => '<p>Body</p>'], \JSON_THROW_ON_ERROR)],
            ['GET', '/api/drafts/{id}'],
            ['PUT', '/api/drafts/{id}', json_encode(['title' => 'Renamed', 'html' => '<p>New</p>'], \JSON_THROW_ON_ERROR)],
            ['GET', '/api/sites/1/drafts'],
            ['DELETE', '/api/drafts/{id}'],
            ['GET', '/api/drafts/{id}'],
        ]];

        yield 'local media, with a binary response' => [[
            ['POST', '/api/sites/1/media', json_encode(['dataBase64' => $png, 'mime' => 'image/png'], \JSON_THROW_ON_ERROR)],
            ['GET', '/api/media/{id}/raw'],
            ['GET', '/api/media/{id}/target'],
            ['POST', '/api/media/{id}/rename', '{"filename":"a/b"}'],
            ['POST', '/api/media/{id}/rename', '{"filename":"renamed"}'],
            ['GET', '/api/sites/1/local-media'],
            ['GET', '/api/media/{id}'],
            ['DELETE', '/api/media/{id}'],
            ['GET', '/api/media/{id}/raw'],
        ]];

        yield 'AI tools, services and chats' => [[
            ['GET', '/api/ai/tools'],
            ['PUT', '/api/ai/system-prompt', '{"prompt":"Be terse."}'],
            ['PATCH', '/api/ai/tools/proofread', '{"enabled":false}'],
            ['POST', '/api/ai/tools', '{"toolKey":"my_tool","title":"Mine","prompt":"Do it"}'],
            ['DELETE', '/api/ai/tools/does_not_exist'],
            ['POST', '/api/ai/services', '{"name":"OpenAI","provider":"openai","endpoint":"https://api.openai.com","model":"gpt-4o","apiKey":"sk-test"}'],
            ['GET', '/api/ai/services'],
            ['GET', '/api/ai/services/{id}/resolved'],
            ['POST', '/api/ai/services', '{"name":"Plain","provider":"custom","endpoint":"http://example.com","model":"m"}'],
            ['POST', '/api/sites/1/drafts', '{"title":"Chat host"}'],
            ['POST', '/api/ai/chats', '{"draftId":{id},"title":"First","messages":[{"role":"user","content":"Hi"}]}'],
            ['GET', '/api/drafts/{draftId}/chats'],
        ]];

        // A file or folder picked in the native shell's dialogs arrives as X-Shell-File-Path (gh-85).
        yield 'files picked in the shell' => [[
            ['POST', '/api/dialog/open-file', '{"filter":"any"}', ['x-shell-file-path' => __FILE__]],
            ['POST', '/api/dialog/open-file', '{"filter":"any"}', ['x-shell-file-path' => '/nonexistent/picked.png']],
            ['POST', '/api/dialog/open-file', '{"filter":"any"}'],
            ['POST', '/api/request-log/export', '{}', ['x-shell-file-path' => '/nonexistent/folder']],
        ]];

        yield 'static assets and the SPA shell' => [[
            ['GET', '/'],
            ['GET', '/js/app.js'],
            ['GET', '/css/app.css?v=1'],
            ['HEAD', '/css/app.css'],
            ['GET', '/some/client/route'],
            ['GET', '/../composer.json'],
        ]];
    }

    /** @param list<array{0: string, 1: string, 2?: string, 3?: array<string, string>}> $steps */
    #[DataProvider('scenarios')]
    public function testFeatureRequestsAnswerIdenticallyThroughThePipe(array $steps): void
    {
        $kernel = PipeApp::container()->get(Kernel::class);
        $worker = new WorkerProcess([\PHP_BINARY, __DIR__ . '/feature-worker.php'], \dirname(__DIR__, 2), self::isolatedEnv());
        $vars   = [];

        foreach ($steps as $i => $step) {
            $method = $step[0];
            $path   = self::substitute($step[1], $vars);
            $body    = self::substitute($step[2] ?? '', $vars);
            $headers = $step[3] ?? [];
            $label   = sprintf('step %d: %s %s', $i, $method, $path);

            $direct = $kernel->handle(new Request($method, 'boson://app' . $path, $headers, $body));
            $piped  = $worker->request(new Request($method, 'boson://app' . $path, $headers, $body));

            self::assertSame($direct->status, $piped->status, "$label: status");
            self::assertSame($direct->headers->toArray(), $piped->headers->toArray(), "$label: headers");

            if (str_starts_with((string) $direct->header('content-type'), 'application/json')) {
                self::assertSame(self::masked($direct->body), self::masked($piped->body), "$label: body");
                $vars = self::remember($direct->body, $vars);
            } else {
                self::assertSame(\strlen($direct->body), \strlen($piped->body), "$label: body length");
                self::assertSame(hash('sha256', $direct->body), hash('sha256', $piped->body), "$label: body");
            }
        }

        $worker->closeInput();
        self::assertSame(Worker::EXIT_OK, $worker->wait());
        self::assertStringNotContainsString('[stray-output]', $worker->stderr());
    }

    /** @param array<string, string> $vars */
    private static function substitute(string $text, array $vars): string
    {
        return (string) preg_replace_callback(
            '/\{([A-Za-z]+)\}/',
            static fn (array $m): string => $vars[$m[1]] ?? $m[0],
            $text,
        );
    }

    /**
     * Picks `data.id` and `data.home` out of a response for later steps. A draft's id is also kept
     * as `draftId`, so a later step can still name it after another resource takes `{id}`.
     *
     * @param array<string, string> $vars
     *
     * @return array<string, string>
     */
    private static function remember(string $body, array $vars): array
    {
        $json = json_decode($body, true);
        $data = \is_array($json) ? ($json['data'] ?? null) : null;

        if (!\is_array($data)) {
            return $vars;
        }

        foreach (['id', 'home'] as $key) {
            if (isset($data[$key]) && (\is_int($data[$key]) || \is_string($data[$key]))) {
                $vars[$key] = (string) $data[$key];
            }
        }

        if (isset($data['id'], $data['siteId']) && \is_int($data['id'])) {
            $vars['draftId'] = (string) $data['id'];
        }

        return $vars;
    }

    /**
     * A JSON body with its wall-clock timestamps blanked: the two sides answer a moment apart, and
     * nothing else in an answer may differ.
     */
    private static function masked(string $body): string
    {
        $json = json_decode($body, true);

        if ($json === null) {
            return $body;
        }

        array_walk_recursive($json, static function (mixed &$value): void {
            if (\is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}/', $value) === 1) {
                $value = '<timestamp>';
            }
        });

        return (string) json_encode($json, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
    }
}
