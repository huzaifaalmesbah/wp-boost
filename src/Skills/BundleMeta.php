<?php

declare(strict_types=1);

namespace Huzaifa\WpBoost\Skills;

/**
 * Read/write the bundle metadata file (`skills/.bundle.json`) that records which upstream
 * commit the bundled skills came from. The install/update commands copy this into the
 * project's `wp-boost.lock.json` so we can detect when a project is behind the bundle.
 */
final class BundleMeta
{
    public const FILENAME = '.bundle.json';

    public static function path(string $skillsDir): string
    {
        return rtrim($skillsDir, '/') . '/' . self::FILENAME;
    }

    /** @return array{syncedAt?:string,sha?:string,source?:string}|null */
    public static function read(string $skillsDir): ?array
    {
        $file = self::path($skillsDir);
        if (! is_file($file)) {
            return null;
        }
        $data = json_decode((string) file_get_contents($file), true);
        return is_array($data) ? $data : null;
    }

    /** @param array{syncedAt:string,sha:string,source:string} $data */
    public static function write(string $skillsDir, array $data): void
    {
        file_put_contents(self::path($skillsDir), json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    }

    public static function shortSha(?string $sha): string
    {
        return $sha ? substr($sha, 0, 7) : 'unknown';
    }
}
