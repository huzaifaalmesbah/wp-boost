<?php

declare(strict_types=1);

namespace Huzaifa\WpBoost\Detection;

use Huzaifa\WpBoost\Support\Paths;

/**
 * Loads project-type presets from `presets.json`. Each preset has a display name and a list of
 * recommended skill names. Contributors can add/tweak presets by editing the JSON file only.
 */
final class PresetRegistry
{
    /** @var array<string,array{displayName:string,recommendedSkills:array<int,string>}> */
    private array $presets;

    /** @param array<string,array{displayName:string,recommendedSkills:array<int,string>}> $presets */
    public function __construct(array $presets)
    {
        $this->presets = $presets;
    }

    public static function fromManifest(?string $path = null): self
    {
        $file = $path ?? Paths::presetsManifest();
        $presets = [];

        if (is_file($file)) {
            $decoded = json_decode((string) file_get_contents($file), true);
            if (is_array($decoded) && is_array($decoded['presets'] ?? null)) {
                foreach ($decoded['presets'] as $name => $entry) {
                    if (! is_string($name) || ! is_array($entry)) continue;
                    $presets[$name] = [
                        'displayName' => (string) ($entry['displayName'] ?? $name),
                        'recommendedSkills' => array_values(array_filter(
                            (array) ($entry['recommendedSkills'] ?? []),
                            'is_string',
                        )),
                    ];
                }
            }
        }

        return new self($presets);
    }

    /** @return array<int,string> */
    public function names(): array
    {
        return array_keys($this->presets);
    }

    public function has(string $name): bool
    {
        return isset($this->presets[$name]);
    }

    public function displayName(string $name): string
    {
        return $this->presets[$name]['displayName'] ?? $name;
    }

    /** @return array<int,string> */
    public function recommendedSkills(string $name): array
    {
        return $this->presets[$name]['recommendedSkills']
            ?? $this->presets['unknown']['recommendedSkills']
            ?? [];
    }
}
