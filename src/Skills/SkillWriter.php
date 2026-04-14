<?php

declare(strict_types=1);

namespace Huzaifa\WpBoost\Skills;

use Symfony\Component\Filesystem\Filesystem;
use Huzaifa\WpBoost\Agents\Agent;

/**
 * Writes selected skills into an agent's skills directory inside the target project.
 * Supports two output formats: a full directory mirror (default) and a single-file `.mdc` for Cursor.
 */
final class SkillWriter
{
    private Filesystem $fs;

    public function __construct(private readonly string $projectRoot)
    {
        $this->fs = new Filesystem();
    }

    /**
     * @param array<string,Skill> $skills
     * @return array{written:int,skipped:int}
     */
    public function sync(Agent $agent, array $skills): array
    {
        $target = $this->projectRoot . '/' . ltrim($agent->skillsPath(), '/');
        $this->fs->mkdir($target, 0755);

        $written = 0;
        $skipped = 0;

        foreach ($skills as $skill) {
            $dest = $target . '/' . $skill->name;

            if ($agent->skillFormat() === 'mdc') {
                // Cursor expects a single .mdc file per rule; nested scripts/references/ from the
                // bundled skill are not copied in this format.
                $dest .= '.mdc';
                $this->writeMdc($skill, $dest);
            } else {
                $this->fs->mirror($skill->path, $dest, null, ['override' => true, 'delete' => false]);
            }
            $written++;
        }

        return ['written' => $written, 'skipped' => $skipped];
    }

    private function writeMdc(Skill $skill, string $dest): void
    {
        $body = (string) file_get_contents($skill->path . '/SKILL.md');
        $mdc = "---\ndescription: {$skill->description}\nglobs: **/*.php, **/*.js\nalwaysApply: false\n---\n\n" . $this->stripFrontmatter($body);
        $this->fs->dumpFile($dest, $mdc);
    }

    private function stripFrontmatter(string $s): string
    {
        return (string) preg_replace('/^---\s*\n.*?\n---\s*\n/s', '', $s);
    }
}
