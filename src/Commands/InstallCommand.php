<?php

declare(strict_types=1);

namespace Huzaifa\WpBoost\Commands;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Huzaifa\WpBoost\Agents\AgentRegistry;
use Huzaifa\WpBoost\Detection\ProjectType;
use Huzaifa\WpBoost\Skills\BundleMeta;
use Huzaifa\WpBoost\Skills\SkillComposer;
use Huzaifa\WpBoost\Skills\SkillWriter;
use Huzaifa\WpBoost\Support\Paths;

use function Laravel\Prompts\multiselect;
use function Laravel\Prompts\note;
use function Laravel\Prompts\info;
use function Laravel\Prompts\intro;

/**
 * Interactive `install` command: detects project type and present agents, then copies the chosen
 * skills into each agent's skills directory and writes a `wp-boost.lock.json` for later re-sync.
 */
#[AsCommand(name: 'install', description: 'Install WordPress agent skills for your selected AI agents.')]
final class InstallCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addOption('agents', null, InputOption::VALUE_OPTIONAL, 'Comma-separated agent names (skips prompt)')
            ->addOption('skills', null, InputOption::VALUE_OPTIONAL, 'Comma-separated skill names (skips prompt)')
            ->addOption('preset', null, InputOption::VALUE_OPTIONAL, 'Preset: plugin | block-theme | classic-theme | bedrock')
            ->addOption('yes', 'y', InputOption::VALUE_NONE, 'Accept recommended defaults, no prompts')
            ->addOption('path', null, InputOption::VALUE_OPTIONAL, 'Project path (default: cwd)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $projectRoot = $input->getOption('path') ?: Paths::projectRoot();
        $projectRoot = realpath($projectRoot) ?: $projectRoot;

        intro('wp-boost · install WordPress agent skills');
        note("Project: {$projectRoot}");

        $type = $input->getOption('preset') ?: ProjectType::detect($projectRoot);
        info("Detected project type: <info>{$type}</info>");

        $registry = new AgentRegistry();
        $composer = SkillComposer::fromBundled();
        $allSkills = $composer->discover();

        if ($allSkills === []) {
            $output->writeln('<error>No skills found in bundle. Run `wp-boost update --remote` to fetch them.</error>');
            return Command::FAILURE;
        }

        $detectedAgents = $registry->detectInProject($projectRoot);
        $agentChoices = [];
        foreach ($registry->all() as $name => $agent) {
            $agentChoices[$name] = $agent->displayName();
        }

        $selectedAgents = $this->resolveList(
            (string) $input->getOption('agents'),
            $agentChoices,
            $detectedAgents ?: array_keys($agentChoices),
            'Which AI agents would you like to configure?',
            (bool) $input->getOption('yes'),
        );

        if ($selectedAgents === []) {
            $output->writeln('<comment>No agents selected. Aborting.</comment>');
            return Command::SUCCESS;
        }

        $skillChoices = [];
        foreach ($allSkills as $skill) {
            $label = $skill->displayName;
            if ($skill->description !== '') {
                $label .= ' — ' . $skill->description;
            }
            $skillChoices[$skill->name] = $label;
        }

        $defaultSkills = array_values(array_intersect(
            array_keys($skillChoices),
            ProjectType::recommendedSkills($type),
        ));

        $selectedSkills = $this->resolveList(
            (string) $input->getOption('skills'),
            $skillChoices,
            $defaultSkills,
            'Which WordPress skills would you like to install?',
            (bool) $input->getOption('yes'),
        );

        if ($selectedSkills === []) {
            $output->writeln('<comment>No skills selected. Aborting.</comment>');
            return Command::SUCCESS;
        }

        $skillsToInstall = array_intersect_key($allSkills, array_flip($selectedSkills));
        $writer = new SkillWriter($projectRoot);

        $output->writeln('');
        foreach ($selectedAgents as $agentName) {
            $agent = $registry->get($agentName);
            if (! $agent) continue;

            $output->write(sprintf('  %-18s ... ', $agent->displayName()));
            try {
                $writer->sync($agent, $skillsToInstall);
                $output->writeln('<info>✓</info> ' . $agent->skillsPath());
            } catch (\Throwable $e) {
                $output->writeln('<error>✗ ' . $e->getMessage() . '</error>');
            }
        }

        $this->writeLock($projectRoot, $selectedAgents, array_keys($skillsToInstall));

        $output->writeln('');
        $output->writeln('<info>Done.</info> Run <comment>wp-boost update --remote</comment> to refresh skills.');
        $output->writeln('Use <comment>wp-boost sync --upstream</comment> to pull from WordPress/agent-skills@trunk (bleeding edge).');

        return Command::SUCCESS;
    }

    /**
     * @param array<string,string> $choices
     * @param array<int,string> $defaults
     * @return array<int,string>
     */
    private function resolveList(string $explicit, array $choices, array $defaults, string $label, bool $yes): array
    {
        if ($explicit !== '') {
            return array_values(array_filter(
                array_map('trim', explode(',', $explicit)),
                fn ($v) => isset($choices[$v]),
            ));
        }

        if ($yes) {
            return $defaults;
        }

        return multiselect(
            label: $label,
            options: $choices,
            default: $defaults,
            required: true,
            scroll: 15,
        );
    }

    /**
     * @param array<int,string> $agents
     * @param array<int,string> $skills
     */
    private function writeLock(string $projectRoot, array $agents, array $skills): void
    {
        $lock = [
            'version' => 1,
            'installedAt' => date(DATE_ATOM),
            'agents' => array_values($agents),
            'skills' => array_values($skills),
            'bundle' => BundleMeta::read(Paths::bundledSkillsDir()),
        ];
        @file_put_contents($projectRoot . '/wp-boost.lock.json', json_encode($lock, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    }
}
