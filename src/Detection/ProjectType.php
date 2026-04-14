<?php

declare(strict_types=1);

namespace Huzaifa\WpBoost\Detection;

/** Detects the WordPress project type (plugin, theme, Bedrock, core) and maps it to recommended skills. */
final class ProjectType
{
    public const PLUGIN = 'plugin';
    public const CLASSIC_THEME = 'classic-theme';
    public const BLOCK_THEME = 'block-theme';
    public const BEDROCK = 'bedrock';
    public const WORDPRESS_CORE = 'core';
    public const UNKNOWN = 'unknown';

    public static function detect(string $path): string
    {
        if (is_file($path . '/composer.json')) {
            $json = @json_decode((string) file_get_contents($path . '/composer.json'), true);
            if (is_array($json)) {
                $name = (string) ($json['name'] ?? '');
                $req = array_merge((array) ($json['require'] ?? []), (array) ($json['require-dev'] ?? []));
                if (isset($req['roots/bedrock']) || str_contains($name, 'bedrock')) {
                    return self::BEDROCK;
                }
            }
        }

        if (is_file($path . '/theme.json') || is_file($path . '/templates/index.html')) {
            return self::BLOCK_THEME;
        }

        if (is_file($path . '/style.css')) {
            return self::CLASSIC_THEME;
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

    /** @return array<int,string> recommended skill names for a project type */
    public static function recommendedSkills(string $type): array
    {
        return match ($type) {
            self::PLUGIN => ['wp-plugin-development', 'wp-wpcli-and-ops', 'wp-phpstan', 'wp-rest-api', 'wp-abilities-api'],
            self::BLOCK_THEME => ['wp-block-themes', 'wp-block-development', 'wp-interactivity-api', 'wp-performance'],
            self::CLASSIC_THEME => ['wp-block-development', 'wp-performance', 'wp-wpcli-and-ops'],
            self::BEDROCK, self::WORDPRESS_CORE => ['wp-plugin-development', 'wp-wpcli-and-ops', 'wp-performance', 'wp-project-triage'],
            default => ['wp-plugin-development', 'wp-wpcli-and-ops'],
        };
    }
}
