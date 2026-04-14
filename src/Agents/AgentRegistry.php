<?php

declare(strict_types=1);

namespace Huzaifa\WpBoost\Agents;

use RuntimeException;
use Huzaifa\WpBoost\Support\Paths;

/** Loads the agents.json manifest, exposes Agent objects, and detects which agents are in use in a project. */
final class AgentRegistry
{
    /** @var array<string,Agent> */
    private array $agents = [];

    /** @var array<string,mixed> */
    private array $manifest = [];

    public function __construct(?string $manifestPath = null)
    {
        $path = $manifestPath ?? Paths::agentsManifest();
        if (! is_file($path)) {
            throw new RuntimeException("agents.json not found at {$path}");
        }

        $data = json_decode((string) file_get_contents($path), true);
        if (! is_array($data) || ! isset($data['agents']) || ! is_array($data['agents'])) {
            throw new RuntimeException('agents.json is malformed.');
        }

        $this->manifest = $data;

        foreach ($data['agents'] as $name => $agentData) {
            $this->agents[(string) $name] = new Agent((string) $name, (array) $agentData);
        }
    }

    /** @return array<string,Agent> */
    public function all(): array
    {
        return $this->agents;
    }

    public function get(string $name): ?Agent
    {
        return $this->agents[$name] ?? null;
    }

    /** @return array<string,mixed> */
    public function manifest(): array
    {
        return $this->manifest;
    }

    /**
     * Detect which agents appear installed in the given project path.
     *
     * @return array<int,string> agent names
     */
    public function detectInProject(string $projectPath): array
    {
        $detected = [];
        foreach ($this->agents as $name => $agent) {
            $d = $agent->detection();

            foreach (($d['paths'] ?? []) as $p) {
                if (is_dir($projectPath . DIRECTORY_SEPARATOR . $p)) {
                    $detected[] = $name;
                    continue 2;
                }
            }

            foreach (($d['files'] ?? []) as $f) {
                if (is_file($projectPath . DIRECTORY_SEPARATOR . $f)) {
                    $detected[] = $name;
                    continue 2;
                }
            }

            foreach (($d['commands'] ?? []) as $cmd) {
                if ($this->commandExists($cmd)) {
                    $detected[] = $name;
                    continue 2;
                }
            }
        }

        return array_values(array_unique($detected));
    }

    private function commandExists(string $cmd): bool
    {
        $which = stripos(PHP_OS_FAMILY, 'Windows') === 0 ? 'where' : 'command -v';
        $out = @shell_exec($which . ' ' . escapeshellarg($cmd) . ' 2>/dev/null');
        return is_string($out) && trim($out) !== '';
    }
}
