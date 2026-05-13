<?php

declare(strict_types=1);

namespace Huzaifa\WpBoost\Setup;

use Huzaifa\WpBoost\Skills\BundleMeta;
use Huzaifa\WpBoost\Skills\RemoteFetcher;
use Huzaifa\WpBoost\Support\Paths;

/**
 * Runs automatically after `composer install` / `composer global require`.
 *
 * Syncs the bundled skills from huzaifaalmesbah/wp-boost@main so users
 * always get the latest vetted skills — even between composer releases.
 *
 * This is silent on success (no output pollution during install).
 * On failure, it prints a warning but never exits non-zero (non-blocking).
 */
final class PostInstallSync
{
    public static function run(): void
    {
        // Only run on "composer install" (not "composer update" or "composer dump-autoload")
        // Composer sets COMPOSER_INSTALL env var during post-install-cmd
        $isInstall = getenv('COMPOSER_INSTALL') !== false
            || (isset($GLOBALS['argv']) && in_array('install', $GLOBALS['argv'], true));

        // Always attempt sync — it's fast and ensures fresh skills
        try {
            $fetcher = new RemoteFetcher(); // defaults to huzaifaalmesbah/wp-boost@main
            $targetDir = Paths::bundledSkillsDir();

            // Check if we should sync (skip if recently synced, e.g. < 1 hour ago)
            if (self::recentlySynced($targetDir)) {
                return;
            }

            $count = $fetcher->fetchInto($targetDir);
            $sha = $fetcher->fetchedSha();

            BundleMeta::write($targetDir, [
                'syncedAt' => date(DATE_ATOM),
                'sha' => $sha ?? '',
                'source' => $fetcher->source(),
            ]);

            // Silent success — don't pollute composer output
        } catch (\Throwable $e) {
            // Non-blocking: warn but don't fail the install
            // Users can always run `wp-boost sync` manually later
            fprintf(STDERR, "  <comment>wp-boost:</comment> Could not auto-sync skills (%s). Run `wp-boost sync` manually.\n", $e->getMessage());
        }
    }

    /**
     * Check if skills were synced recently (within the last hour).
     * Avoids unnecessary network calls on repeated installs.
     */
    private static function recentlySynced(string $skillsDir): bool
    {
        $meta = BundleMeta::read($skillsDir);
        $syncedAt = $meta['syncedAt'] ?? null;

        if ($syncedAt === null) {
            return false;
        }

        try {
            $syncedTime = new \DateTimeImmutable($syncedAt);
            $oneHourAgo = new \DateTimeImmutable('1 hour ago');
            return $syncedTime > $oneHourAgo;
        } catch (\Throwable) {
            return false;
        }
    }
}
