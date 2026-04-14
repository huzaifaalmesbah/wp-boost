<?php

declare(strict_types=1);

namespace Huzaifa\WpBoost\Agents;

/** Value object describing one AI agent target (skills path, display name, detection hints) from agents.json. */
final class Agent
{
    /** @param array<string,mixed> $data */
    public function __construct(
        public readonly string $name,
        public readonly array $data,
    ) {}

    public function displayName(): string
    {
        return (string) ($this->data['displayName'] ?? $this->name);
    }

    public function skillsPath(): string
    {
        return (string) ($this->data['skillsPath'] ?? '.ai/skills');
    }

    public function guidelinesPath(): ?string
    {
        return isset($this->data['guidelinesPath']) ? (string) $this->data['guidelinesPath'] : null;
    }

    public function skillFormat(): string
    {
        return (string) ($this->data['skillFormat'] ?? 'md');
    }

    /** @return array{paths?:array<string>,files?:array<string>,commands?:array<string>} */
    public function detection(): array
    {
        return (array) ($this->data['detect'] ?? []);
    }
}
