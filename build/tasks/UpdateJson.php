<?php
/**
 * Grafida — Joomla content editing, untethered.
 *
 * @copyright Copyright (c) 2023-2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

namespace tasks;

use DateTime;
use Phing\Exception\BuildException;
use Phing\Task;

/**
 * Fetches the latest stable release from GitHub and writes a JSON summary to a file.
 *
 * Attributes:
 *  - organization  GitHub organisation name (e.g. "grafida")
 *  - repository    GitHub repository name   (e.g. "grafida")
 *  - token         GitHub personal access token (optional, avoids rate-limiting)
 *  - outfile       Absolute path to the output file (e.g. "${dirs.release}/update.json")
 */
class UpdateJson extends Task
{
    private string $organization = '';

    private string $repository = '';

    private string $token = '';

    private string $outfile = '';

    public function setOrganization(string $organization): void
    {
        $this->organization = $organization;
    }

    public function setRepository(string $repository): void
    {
        $this->repository = $repository;
    }

    public function setToken(string $token): void
    {
        $this->token = $token;
    }

    public function setOutfile(string $outfile): void
    {
        $this->outfile = $outfile;
    }

    public function main(): void
    {
        if (empty($this->organization) || empty($this->repository))
        {
            throw new BuildException('Both organization and repository attributes must be specified.');
        }

        if (empty($this->outfile))
        {
            throw new BuildException('The outfile attribute must be specified.');
        }

        $releases = $this->fetchReleases();
        $latest   = $this->findLatestRelease($releases);

        if ($latest === null)
        {
            throw new BuildException(
                sprintf(
                    'No suitable stable release found in %s/%s on GitHub.',
                    $this->organization,
                    $this->repository
                )
            );
        }

        $data   = $this->buildData($latest);
        $outDir = dirname($this->outfile);

        if (!is_dir($outDir))
        {
            mkdir($outDir, 0755, true);
        }

        file_put_contents(
            $this->outfile,
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n"
        );

        $this->log(
            sprintf("Written update.json for version %s to %s", $data['version'], $this->outfile)
        );
    }

    /**
     * Fetch all releases from the GitHub Releases API.
     *
     * @return  array<int, array<string, mixed>>
     * @throws  BuildException
     */
    private function fetchReleases(): array
    {
        $url = sprintf('https://api.github.com/repos/%s/%s/releases', $this->organization, $this->repository);

        $headers = [
            'Accept: application/vnd.github+json',
            'X-GitHub-Api-Version: 2022-11-28',
            'User-Agent: grafida-phing-build/1.0',
        ];

        if (!empty($this->token))
        {
            $headers[] = 'Authorization: Bearer ' . $this->token;
        }

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch);
        curl_close($ch);

        if ($response === false)
        {
            throw new BuildException('cURL error fetching GitHub releases: ' . $error);
        }

        if ($httpCode !== 200)
        {
            throw new BuildException(
                sprintf('GitHub API returned HTTP %d when fetching releases.', $httpCode)
            );
        }

        $releases = json_decode($response, true);

        if (!is_array($releases))
        {
            throw new BuildException('Could not parse the GitHub releases response as JSON.');
        }

        return $releases;
    }

    /**
     * File extensions that count as a downloadable release artifact.
     *
     * Grafida ships a per-platform set (macOS .dmg, Windows .exe installer or .zip, Linux .tar.gz)
     * plus the cross-platform .phar; any of these marks a release as a real, downloadable one.
     */
    private const ASSET_EXTENSIONS = ['.zip', '.exe', '.dmg', '.tar.gz'];

    /**
     * What each platform's build of this release needs (gh-92), written as grafida.json's
     * `requires`; UpdateService (0.4 and later) calls a release that excludes this machine "no
     * update", which is how a platform that loses support stops being notified. Edit it when a
     * release drops or adds a platform: Intel Macs go when macOS 29 lands. The minimums are the
     * shell's floors: macOS 12, and Windows 10 22H2 (build 19045). Linux has no OS version to require.
     */
    private const REQUIRES = [
        'macos'   => ['arch' => ['arm64', 'x86_64'], 'min' => '12.0'],
        'windows' => ['arch' => ['x86_64'], 'min' => '10.0.19045'],
        'linux'   => ['arch' => ['x86_64', 'arm64']],
    ];

    /**
     * Return the first release that is not a draft, not a pre-release, and has a downloadable asset.
     *
     * GitHub returns releases in reverse-chronological order, so the first match is the latest stable.
     *
     * @param   array<int, array<string, mixed>>  $releases
     *
     * @return  array<string, mixed>|null
     */
    private function findLatestRelease(array $releases): ?array
    {
        foreach ($releases as $release)
        {
            if ($release['draft'] || $release['prerelease'])
            {
                continue;
            }

            foreach ($release['assets'] ?? [] as $asset)
            {
                if ($this->isDownloadableAsset($asset))
                {
                    return $release;
                }
            }
        }

        return null;
    }

    /**
     * Is this release asset a finished, downloadable artifact (uploaded, with a recognised extension)?
     *
     * @param   array<string, mixed>  $asset
     */
    private function isDownloadableAsset(array $asset): bool
    {
        if (($asset['state'] ?? '') !== 'uploaded')
        {
            return false;
        }

        $name = (string) ($asset['name'] ?? '');

        foreach (self::ASSET_EXTENSIONS as $extension)
        {
            if (str_ends_with($name, $extension))
            {
                return true;
            }
        }

        return false;
    }

    /**
     * The bare version number of a GitHub release: its tag ("0.4"), or failing that the first run of
     * dot-separated digits in its name.
     *
     * Never the name itself: it is "Grafida 0.4", and every Grafida compares versions with
     * version_compare(), which ranks a leading word below every number. Up to 0.3 the document
     * carried the name, so no installed copy was ever offered an update (gh-92). 0.3 clients compare
     * the field as it is, so this is the fix that reaches them.
     *
     * @param   array<string, mixed>  $release  A single GitHub release object decoded from JSON.
     */
    public static function versionOf(array $release): string
    {
        foreach ([$release['tag_name'] ?? null, $release['name'] ?? null] as $candidate)
        {
            if (\is_string($candidate) && preg_match('/\d+(?:\.\d+)*/', $candidate, $m) === 1)
            {
                return $m[0];
            }
        }

        throw new BuildException('The release has no version number in its tag or its name.');
    }

    /**
     * Build the associative array that will be serialised to JSON.
     *
     * @param   array<string, mixed>  $release  A single GitHub release object decoded from JSON.
     *
     * @return  array<string, mixed>
     */
    private function buildData(array $release): array
    {
        $version = self::versionOf($release);

        $date        = (new DateTime($release['published_at']))->format('Y-m-d');
        $downloadUrl = null;

        foreach ($release['assets'] ?? [] as $asset)
        {
            if ($this->isDownloadableAsset($asset))
            {
                $downloadUrl = $asset['browser_download_url'];
                break;
            }
        }

        return [
            'version'      => $version,
            'date'         => $date,
            'infoURL'      => $release['html_url'],
            'download'     => $downloadUrl,
            'releaseNotes' => $release['body'] ?? '',
            'requires'     => self::REQUIRES,
        ];
    }
}
