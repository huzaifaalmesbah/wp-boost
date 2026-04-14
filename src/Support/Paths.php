<?php

declare(strict_types=1);

namespace Huzaifa\WpBoost\Support;

/** Centralizes filesystem paths used across the package (bundle root, skills dir, manifest, cwd). */
final class Paths
{
    public static function packageRoot(): string
    {
        return dirname(__DIR__, 2);
    }

    public static function bundledSkillsDir(): string
    {
        return self::packageRoot() . '/skills';
    }

    public static function agentsManifest(): string
    {
        return self::packageRoot() . '/agents.json';
    }

    public static function projectRoot(): string
    {
        return getcwd() ?: self::packageRoot();
    }

    public static function lockFile(): string
    {
        return self::projectRoot() . '/wp-boost.lock.json';
    }
}
