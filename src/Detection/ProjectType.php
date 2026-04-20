<?php

declare(strict_types=1);

namespace Huzaifa\WpBoost\Detection;

/** Detects the WordPress project type (plugin, block theme, core) and maps it to recommended skills. */
final class ProjectType
{
    public const PLUGIN = 'plugin';
    public const BLOCK_THEME = 'block-theme';
    public const WORDPRESS_CORE = 'core';
    public const UNKNOWN = 'unknown';

    public static function detect(string $path): string
    {
        if (is_file($path . '/theme.json') || is_file($path . '/templates/index.html')) {
            return self::BLOCK_THEME;
        }

        if (is_dir($path . '/wp-content') && is_file($path . '/wp-config.php')) {
            return self::WORDPRESS_CORE;
        }

        foreach (glob($path . '/*.php') ?: [] as $file) {
            $head = (string) @file_get_contents($file, false, null, 0, 4096);
            if (preg_match('/^\s*\*\s*Plugin Name:/mi', $head)) {
                return self::PLUGIN;
            }
        }

        return self::UNKNOWN;
    }

    /** @return array<int,string> recommended skill names for a project type (sourced from presets.json) */
    public static function recommendedSkills(string $type): array
    {
        return PresetRegistry::fromManifest()->recommendedSkills($type);
    }
}
