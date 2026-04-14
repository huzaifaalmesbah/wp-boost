<?php

declare(strict_types=1);

namespace Huzaifa\WpBoost\Commands;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Huzaifa\WpBoost\Skills\BundleMeta;
use Huzaifa\WpBoost\Skills\RemoteFetcher;
use Huzaifa\WpBoost\Support\Paths;

/**
 * `sync` command: refreshes wp-boost's bundled skills/ directory from the vetted channel
 * (huzaifaalmesbah/wp-boost@main). Pass `--upstream` to pull directly from WordPress/agent-skills@trunk
 * instead. No project is required — after syncing, run `wp-boost update` inside each project
 * to apply the new skills.
 */
#[AsCommand(name: 'sync', description: 'Refresh the wp-boost skills bundle from the official source.')]
final class SyncCommand extends Command
{
    protected function configure(): void
    {
        $this->addOption(
            'upstream',
            null,
            InputOption::VALUE_NONE,
            'Pull bleeding-edge skills directly from WordPress/agent-skills@trunk (bypasses the vetted channel).'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
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

        $output->writeln("<info>Fetched {$count} skills</info> (" . BundleMeta::shortSha($sha) . ") into " . Paths::bundledSkillsDir());
        $output->writeln('');
        $output->writeln('<info>Bundle refreshed.</info> Run <comment>wp-boost update</comment> inside each project to apply.');

        return Command::SUCCESS;
    }
}
