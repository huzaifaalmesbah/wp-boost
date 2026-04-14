<?php

declare(strict_types=1);

namespace Huzaifa\WpBoost\Commands;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Huzaifa\WpBoost\Agents\AgentRegistry;
use Huzaifa\WpBoost\Detection\ProjectType;
use Huzaifa\WpBoost\Skills\BundleMeta;
use Huzaifa\WpBoost\Skills\SkillComposer;
use Huzaifa\WpBoost\Support\Freshness;
use Huzaifa\WpBoost\Support\Paths;

/** `doctor` command: prints detected project type, detected agents, skills in the bundle, and freshness. */
#[AsCommand(name: 'doctor', description: 'Show detected project type, agents, available skills, and bundle freshness.')]
final class DoctorCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $root = Paths::projectRoot();
        $output->writeln("Project:  <info>{$root}</info>");
        $output->writeln('PHP:      <info>' . PHP_VERSION . '</info>');
        $output->writeln('Type:     <info>' . ProjectType::detect($root) . '</info>');

        $registry = new AgentRegistry();
        $detected = $registry->detectInProject($root);
        $output->writeln('Agents:   <info>' . ($detected ? implode(', ', $detected) : 'none detected') . '</info>');

        $skills = SkillComposer::fromBundled()->discover();
        $output->writeln('Skills:   <info>' . count($skills) . ' available</info>');

        $bundle = BundleMeta::read(Paths::bundledSkillsDir());
        $bundleLine = $bundle
            ? sprintf('%s from %s (%s)', BundleMeta::shortSha($bundle['sha'] ?? null), $bundle['source'] ?? 'unknown', $bundle['syncedAt'] ?? '?')
            : 'not recorded (run `wp-boost sync` to stamp it)';
        $output->writeln("Bundle:   <info>{$bundleLine}</info>");

        $lockFile = $root . '/wp-boost.lock.json';
        $lock = null;
        if (is_file($lockFile)) {
            $decoded = json_decode((string) file_get_contents($lockFile), true);
            $lock = is_array($decoded) ? $decoded : null;
        }

        if ($lock) {
            $lockBundle = is_array($lock['bundle'] ?? null) ? $lock['bundle'] : null;
            $projectLine = $lockBundle
                ? sprintf('%s (installed %s)', BundleMeta::shortSha($lockBundle['sha'] ?? null), $lock['installedAt'] ?? '?')
                : 'installed without bundle stamp';
            $output->writeln("Project:  <info>{$projectLine}</info>");
        } else {
            $output->writeln("Project:  <info>no wp-boost.lock.json (not installed here)</info>");
        }

        Freshness::warnIfStale($output, $lock);

        foreach ($skills as $s) {
            $output->writeln("  - {$s->name}");
        }

        return Command::SUCCESS;
    }
}
