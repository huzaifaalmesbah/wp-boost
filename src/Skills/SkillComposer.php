<?php

declare(strict_types=1);

namespace Huzaifa\WpBoost\Skills;

use Huzaifa\WpBoost\Support\Paths;

/** Scans a skills/ directory, parses each SKILL.md frontmatter, and returns Skill objects keyed by name. */
final class SkillComposer
{
    public function __construct(private readonly string $skillsDir)
    {
    }

    public static function fromBundled(): self
    {
        return new self(Paths::bundledSkillsDir());
    }

    /** @return array<string,Skill> */
    public function discover(): array
    {
        $skills = [];
        if (! is_dir($this->skillsDir)) {
            return $skills;
        }

        foreach (scandir($this->skillsDir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            $path = $this->skillsDir . '/' . $entry;
            if (! is_dir($path)) continue;

            $skillFile = $path . '/SKILL.md';
            if (! is_file($skillFile)) continue;

            [$display, $description] = $this->parseFrontmatter($skillFile, $entry);
            $skills[$entry] = new Skill($entry, $path, $display, $description);
        }

        ksort($skills);
        return $skills;
    }

    /** @return array{0:string,1:string} */
    private function parseFrontmatter(string $file, string $fallbackName): array
    {
        $contents = (string) file_get_contents($file);
        $display = $fallbackName;
        $description = '';

        if (preg_match('/^---\s*\n(.*?)\n---\s*\n/s', $contents, $m)) {
            foreach (explode("\n", $m[1]) as $line) {
                if (preg_match('/^name:\s*(.+)$/i', $line, $mm)) {
                    $display = trim($mm[1], " \"'");
                } elseif (preg_match('/^description:\s*(.+)$/i', $line, $mm)) {
                    $description = trim($mm[1], " \"'");
                }
            }
        }

        return [$display, $description];
    }
}
