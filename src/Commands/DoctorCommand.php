<?php

declare(strict_types=1);

namespace Huzaifa\WpBoost\Commands;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Huzaifa\WpBoost\Agents\AgentRegistry;
use Huzaifa\WpBoost\Detection\PresetRegistry;
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

        $installed = [];
        if ($lock && is_array($lock['skills'] ?? null)) {
            $installed = array_values(array_filter($lock['skills'], 'is_string'));
        }

        $output->writeln('');
        if ($installed !== []) {
            $output->writeln('<comment>Installed in this project (' . count($installed) . '):</comment>');
            foreach ($installed as $name) {
                $output->writeln("  <info>✓</info> {$name}");
            }

            $notInstalled = array_values(array_diff(array_keys($skills), $installed));
            if ($notInstalled !== []) {
                $output->writeln('');
                $output->writeln('<comment>Available in bundle but not installed (' . count($notInstalled) . '):</comment>');
                foreach ($notInstalled as $name) {
                    $output->writeln("  · {$name}");
                }
                $output->writeln('');
                $output->writeln('<comment>Add them with:</comment>');
                $output->writeln('');
                $output->writeln('  <comment>→ Pick interactively</comment>');
                $output->writeln('    <info>wp-boost install</info>');
                $output->writeln('');
                $output->writeln('  <comment>→ Add all ' . count($notInstalled) . ' missing in one shot</comment>');
                $output->writeln('    <info>wp-boost install --skills=' . implode(',', $notInstalled) . ' --yes</info>');
            }
        } else {
            $output->writeln('<comment>Available in bundle (' . count($skills) . '):</comment>');
            foreach ($skills as $s) {
                $output->writeln("  · {$s->name}");
            }
            $output->writeln('');
            $output->writeln('<comment>Install skills into this project:</comment>');
            $output->writeln('');
            $output->writeln('  <comment>→ Pick interactively</comment>');
            $output->writeln('    <info>wp-boost install</info>');
            $output->writeln('');
            $output->writeln('  <comment>→ Install recommended defaults for the detected project type</comment>');
            $output->writeln('    <info>wp-boost install --yes</info>');
        }

        $this->printPresetHints($output, ProjectType::detect($root));

        return Command::SUCCESS;
    }

    private function printPresetHints(OutputInterface $output, string $detected): void
    {
        $registry = PresetRegistry::fromManifest();
        $presets = array_values(array_filter(
            $registry->names(),
            fn (string $name) => $name !== 'unknown' && $name !== 'core',
        ));

        if ($presets === []) {
            return;
        }

        $width = max(array_map('strlen', $presets));

        $output->writeln('');
        $output->writeln('<comment>Install by project type</comment> (override detection if it got it wrong):');
        foreach ($presets as $preset) {
            $marker = $preset === $detected ? '<info>*</info>' : ' ';
            $output->writeln(sprintf(
                '  %s <info>wp-boost install --preset=%s --yes</info>   %s',
                $marker,
                str_pad($preset, $width),
                $registry->displayName($preset),
            ));
        }
        $output->writeln('  <comment>*</comment> = matches auto-detected type for this project');
    }
}
