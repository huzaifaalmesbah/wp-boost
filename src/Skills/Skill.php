<?php

declare(strict_types=1);

namespace Huzaifa\WpBoost\Skills;

/** Value object for a single skill discovered in the bundled skills/ directory. */
final class Skill
{
    public function __construct(
        public readonly string $name,
        public readonly string $path,
        public readonly string $displayName,
        public readonly string $description,
    ) {}
}
