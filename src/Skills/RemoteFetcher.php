<?php

declare(strict_types=1);

namespace Huzaifa\WpBoost\Skills;

use RuntimeException;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Downloads the skills/ tree from a GitHub repo as a tarball and extracts it into the bundled skills dir.
 * Defaults to the `huzaifaalmesbah/wp-boost@main` repo (vetted channel). Pass `WordPress/agent-skills@trunk`
 * for bleeding edge from upstream. After a successful fetch, the resolved commit SHA is available via
 * {@see fetchedSha()}.
 */
final class RemoteFetcher
{
    private Filesystem $fs;

    private ?string $lastSha = null;

    public function __construct(
        private readonly string $repo = 'huzaifaalmesbah/wp-boost',
        private readonly string $branch = 'main',
        private readonly string $subPath = 'skills',
    ) {
        $this->fs = new Filesystem();
    }

    public function source(): string
    {
        return $this->repo . '@' . $this->branch;
    }

    public function fetchedSha(): ?string
    {
        return $this->lastSha;
    }

    /**
     * Download the skills/ tree from the repo into $targetDir.
     * Returns the number of skills written.
     */
    public function fetchInto(string $targetDir): int
    {
        $tmp = sys_get_temp_dir() . '/wp-boost-' . bin2hex(random_bytes(4));
        $this->fs->mkdir($tmp);

        $this->lastSha = $this->resolveSha();
        $ref = $this->lastSha ?? $this->branch;

        $tarball = $tmp . '/src.tar.gz';
        $url = $this->lastSha
            ? "https://codeload.github.com/{$this->repo}/tar.gz/{$ref}"
            : "https://codeload.github.com/{$this->repo}/tar.gz/refs/heads/{$ref}";

        $stream = @fopen($url, 'rb');
        if (! $stream) {
            throw new RuntimeException("Unable to download {$url}. Check network connectivity.");
        }
        file_put_contents($tarball, stream_get_contents($stream));
        fclose($stream);

        $phar = new \PharData($tarball);
        $phar->extractTo($tmp, null, true);

        $topDir = glob($tmp . '/*', GLOB_ONLYDIR)[0] ?? null;
        if (! $topDir) {
            throw new RuntimeException('Extracted tarball has no top-level directory.');
        }

        $extracted = $topDir . '/' . $this->subPath;
        if (! is_dir($extracted)) {
            throw new RuntimeException("Extracted tarball has no {$this->subPath}/ directory.");
        }

        $this->fs->mkdir($targetDir);
        $this->fs->mirror($extracted, $targetDir, null, ['override' => true, 'delete' => false]);

        $count = 0;
        foreach (glob($targetDir . '/*', GLOB_ONLYDIR) ?: [] as $d) {
            if (is_file($d . '/SKILL.md')) $count++;
        }

        $this->fs->remove($tmp);
        return $count;
    }

    /** Best-effort resolution of a branch to its current commit SHA via the GitHub API. */
    private function resolveSha(): ?string
    {
        $url = "https://api.github.com/repos/{$this->repo}/commits/{$this->branch}";
        $ctx = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => "User-Agent: wp-boost\r\nAccept: application/vnd.github.sha\r\n",
                'timeout' => 10,
            ],
        ]);
        $body = @file_get_contents($url, false, $ctx);
        if (! is_string($body) || ! preg_match('/^[0-9a-f]{40}$/', trim($body))) {
            return null;
        }
        return trim($body);
    }
}
