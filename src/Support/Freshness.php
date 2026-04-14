<?php

declare(strict_types=1);

namespace Huzaifa\WpBoost\Support;

use Huzaifa\WpBoost\Skills\BundleMeta;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Compares the bundled skills' version (from `skills/.bundle.json`) against the version stamped
 * into a project's `wp-boost.lock.json`, and renders a banner if the project is behind.
 */
final class Freshness
{
    /**
     * @param array<string,mixed>|null $lock
     * @return array{status:string,bundleSha:?string,lockSha:?string,bundle:?array,lockBundle:?array}
     */
    public static function check(?array $lock): array
    {
        $bundle = BundleMeta::read(Paths::bundledSkillsDir());
        $lockBundle = is_array($lock['bundle'] ?? null) ? $lock['bundle'] : null;

        $bundleSha = is_string($bundle['sha'] ?? null) && $bundle['sha'] !== '' ? $bundle['sha'] : null;
        $lockSha = is_string($lockBundle['sha'] ?? null) && $lockBundle['sha'] !== '' ? $lockBundle['sha'] : null;

        $status = 'unknown';
        if ($bundleSha && $lockSha) {
            $status = $bundleSha === $lockSha ? 'fresh' : 'stale';
        }

        return [
            'status' => $status,
            'bundleSha' => $bundleSha,
            'lockSha' => $lockSha,
            'bundle' => $bundle,
            'lockBundle' => $lockBundle,
        ];
    }

    /** Prints a banner if the project is stale. Safe to call with a missing lock. */
    public static function warnIfStale(OutputInterface $output, ?array $lock): void
    {
        $info = self::check($lock);
        if ($info['status'] !== 'stale') {
            return;
        }

        $bundleShort = BundleMeta::shortSha($info['bundleSha']);
        $lockShort = BundleMeta::shortSha($info['lockSha']);
        $output->writeln('');
        $output->writeln("<comment>⚠  Project is using skills from bundle {$lockShort}; current bundle is {$bundleShort}.</comment>");
        $output->writeln("<comment>   Run `wp-boost update` to apply the new skills.</comment>");
        $output->writeln('');
    }
}
