<?php

declare(strict_types=1);

namespace Huzaifa\WpBoost\Commands;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Huzaifa\WpBoost\Agents\AgentRegistry;
use Huzaifa\WpBoost\Skills\BundleMeta;
use Huzaifa\WpBoost\Skills\RemoteFetcher;
use Huzaifa\WpBoost\Skills\SkillComposer;
use Huzaifa\WpBoost\Skills\SkillWriter;
use Huzaifa\WpBoost\Support\Paths;

/**
 * `update` command: re-syncs bundled skills into the agents recorded in `wp-boost.lock.json`.
 * With `--remote`, first refreshes the bundle from the vetted source (equivalent to running
 * `wp-boost sync` before `wp-boost update`).
 */
#[AsCommand(name: 'update', description: 'Re-sync installed agents from the bundle; optionally refresh the bundle first.')]
final class UpdateCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addOption('remote', null, InputOption::VALUE_NONE, 'Refresh the bundle from upstream before syncing (shortcut for `wp-boost sync` + `wp-boost update`).')
            ->addOption('upstream', null, InputOption::VALUE_NONE, 'With --remote, pull from WordPress/agent-skills@trunk instead of the vetted channel.')
            ->addOption('path', null, InputOption::VALUE_OPTIONAL, 'Project path (default: cwd)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($input->getOption('remote')) {
            $fetcher = $input->getOption('upstream')
                ? new RemoteFetcher('WordPress/agent-skills', 'trunk', 'skills')
                : new RemoteFetcher();

            $output->writeln("Fetching skills from <info>{$fetcher->source()}</info>...");
            $count = $fetcher->fetchInto(Paths::bundledSkillsDir());
            $sha = $fetcher->fetchedSha();
            BundleMeta::write(Paths::bundledSkillsDir(), [
                'syncedAt' => date(DATE_ATOM),
                'sha' => $sha ?? '',
                'source' => $fetcher->source(),
            ]);
            $output->writeln("<info>Fetched {$count} skills</info> (" . BundleMeta::shortSha($sha) . ').');
        }

        $projectRoot = $input->getOption('path') ?: Paths::projectRoot();
        $projectRoot = realpath($projectRoot) ?: $projectRoot;

        $lockPath = $projectRoot . '/wp-boost.lock.json';
        if (! is_file($lockPath)) {
            $output->writeln('<error>No wp-boost.lock.json in project. Run `wp-boost install` first, or use `wp-boost sync` to just refresh the bundle.</error>');
            return Command::FAILURE;
        }

        $lock = json_decode((string) file_get_contents($lockPath), true);
        if (! is_array($lock)) {
            $output->writeln('<error>wp-boost.lock.json is malformed.</error>');
            return Command::FAILURE;
        }

        $registry = new AgentRegistry();
        $allSkills = SkillComposer::fromBundled()->discover();
        $selected = array_intersect_key($allSkills, array_flip((array) ($lock['skills'] ?? [])));
        $writer = new SkillWriter($projectRoot);

        foreach ((array) ($lock['agents'] ?? []) as $name) {
            $agent = $registry->get((string) $name);
            if (! $agent) continue;

            $output->write(sprintf('  %-18s ... ', $agent->displayName()));
            try {
                $writer->sync($agent, $selected);
                $output->writeln('<info>✓</info>');
            } catch (\Throwable $e) {
                $output->writeln('<error>✗ ' . $e->getMessage() . '</error>');
            }
        }

        $lock['installedAt'] = date(DATE_ATOM);
        $lock['bundle'] = BundleMeta::read(Paths::bundledSkillsDir());
        file_put_contents($lockPath, json_encode($lock, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

        return Command::SUCCESS;
    }
}
