<?php

declare(strict_types=1);

namespace Huzaifa\WpBoost\Commands;

use MaplePHP\Prompts\Prompt;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Huzaifa\WpBoost\Agents\AgentRegistry;
use Huzaifa\WpBoost\Detection\PresetRegistry;
use Huzaifa\WpBoost\Detection\ProjectType;
use Huzaifa\WpBoost\Skills\BundleMeta;
use Huzaifa\WpBoost\Skills\SkillComposer;
use Huzaifa\WpBoost\Skills\SkillWriter;
use Huzaifa\WpBoost\Support\Paths;

/**
 * Interactive `install` command: detects project type and present agents, then copies the chosen
 * skills into each agent's skills directory and writes a `wp-boost.lock.json` for later re-sync.
 *
 * Uses maplephp/prompts for cross-platform interactive prompts (works on Windows, macOS, Linux).
 */
#[AsCommand(name: 'install', description: 'Install WordPress agent skills for your selected AI agents.')]
final class InstallCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addOption('agents', null, InputOption::VALUE_OPTIONAL, 'Comma-separated agent names (skips prompt)')
            ->addOption('skills', null, InputOption::VALUE_OPTIONAL, 'Comma-separated skill names (skips prompt)')
            ->addOption('preset', null, InputOption::VALUE_OPTIONAL, 'Preset: plugin | block-theme | core')
            ->addOption('yes', 'y', InputOption::VALUE_NONE, 'Accept recommended defaults, no prompts')
            ->addOption('path', null, InputOption::VALUE_OPTIONAL, 'Project path (default: cwd)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $projectRoot = $input->getOption('path') ?: Paths::projectRoot();
        $projectRoot = realpath($projectRoot) ?: $projectRoot;

        $output->writeln('');
        $output->writeln('  <info>wp-boost</info> · install WordPress agent skills');
        $output->writeln('');

        $registry = new AgentRegistry();
        $composer = SkillComposer::fromBundled();
        $allSkills = $composer->discover();

        if ($allSkills === []) {
            $output->writeln('<error>No skills found in bundle. Run `wp-boost update --remote` to fetch them.</error>');
            return Command::FAILURE;
        }

        $presetOption = $input->getOption('preset');
        if ($presetOption !== null && $presetOption !== '') {
            $presets = PresetRegistry::fromManifest();
            if (! $presets->has($presetOption)) {
                $output->writeln(sprintf(
                    '<error>Unknown preset: %s. Valid presets: %s</error>',
                    $presetOption,
                    implode(', ', $presets->names()),
                ));
                return Command::INVALID;
            }
            $type = $presetOption;
        } else {
            $type = ProjectType::detect($projectRoot);
        }

        $this->printWelcome($output, $projectRoot, $type, $allSkills);

        $detectedAgents = $registry->detectInProject($projectRoot);

        // --- Agent selection ---
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
            $output,
        );

        if ($selectedAgents === []) {
            $output->writeln('<comment>No agents selected. Aborting.</comment>');
            return Command::FAILURE;
        }

        // --- Skill selection ---
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
            $output,
        );

        if ($selectedSkills === []) {
            $output->writeln('<comment>No skills selected. Aborting.</comment>');
            return Command::FAILURE;
        }

        $skillsToInstall = array_intersect_key($allSkills, array_flip($selectedSkills));
        $writer = new SkillWriter($projectRoot);

        $output->writeln('');
        $output->writeln('  <comment>Writing skills…</comment>');
        $output->writeln('');

        $writtenAgents = [];
        foreach ($selectedAgents as $agentName) {
            $agent = $registry->get($agentName);
            if (! $agent) {
                continue;
            }

            $output->write(sprintf('    %-18s ', $agent->displayName()));
            try {
                $writer->sync($agent, $skillsToInstall);
                $output->writeln('<info>✓</info> <comment>' . $agent->skillsPath() . '</comment>');
                $writtenAgents[] = $agent->displayName();
            } catch (\Throwable $e) {
                $output->writeln('<error>✗ ' . $e->getMessage() . '</error>');
            }
        }

        $this->writeLock($projectRoot, $selectedAgents, array_keys($skillsToInstall), $output);
        $this->printSuccess($output, $writtenAgents, $skillsToInstall);

        return Command::SUCCESS;
    }

    private function printWelcome(OutputInterface $output, string $projectRoot, string $type, array $skills): void
    {
        $meta = BundleMeta::read(Paths::bundledSkillsDir());
        $sha = BundleMeta::shortSha($meta['sha'] ?? null);
        $syncedAt = $meta['syncedAt'] ?? 'unknown';
        $source = $meta['source'] ?? 'WordPress/agent-skills@trunk';

        $output->writeln('  <comment>What is this?</comment>');
        $output->writeln('  wp-boost drops curated WordPress skill files into your AI agent\'s');
        $output->writeln('  config (Claude Code, Cursor, Copilot, Codex, and more) so the agent');
        $output->writeln('  learns WordPress conventions before it writes a single line of code.');
        $output->writeln('');
        $output->writeln('  <comment>Project</comment>       ' . $projectRoot);
        $output->writeln('  <comment>Detected as</comment>   <info>' . $type . '</info>');
        $output->writeln(sprintf(
            '  <comment>Skill bundle</comment>  %d skills from <info>%s</info>',
            count($skills),
            $source,
        ));
        $output->writeln(sprintf('                 snapshot <info>%s</info> · synced %s', $sha, $syncedAt));
        $output->writeln('  <comment>wp-boost</comment>      <info>https://github.com/huzaifaalmesbah/wp-boost</info>');
        $output->writeln('  <comment>Upstream</comment>      <info>https://github.com/WordPress/agent-skills</info>');
        $output->writeln('');
    }

    /**
     * @param array<int,string> $writtenAgents
     * @param array<string,\Huzaifa\WpBoost\Skills\Skill> $skills
     */
    private function printSuccess(OutputInterface $output, array $writtenAgents, array $skills): void
    {
        $output->writeln('');
        $output->writeln('  <info>✓ All set.</info> Installed <info>' . count($skills) . '</info> skill(s) for <info>' . count($writtenAgents) . '</info> agent(s).');
        $output->writeln('');
        $output->writeln('  <comment>Skills installed</comment>');
        foreach ($skills as $skill) {
            $desc = $skill->description !== '' ? ' — ' . $skill->description : '';
            $output->writeln('    · <info>' . $skill->displayName . '</info>' . $desc);
        }
        $output->writeln('');
        $output->writeln('  <comment>What\'s next?</comment>');
        $output->writeln('    1. Open your project in an AI agent — it will pick up the new skills automatically.');
        $output->writeln('    2. Ask it something WordPress-y (e.g. "register a custom post type with a block editor template").');
        $output->writeln('    3. Run <info>wp-boost doctor</info> anytime to check for bundle updates.');
        $output->writeln('');
        $output->writeln('  <comment>Handy commands</comment>');
        $output->writeln('    <info>wp-boost update</info>           re-apply the bundled skills to this project');
        $output->writeln('    <info>wp-boost update --remote</info>   pull latest vetted bundle, then re-apply');
        $output->writeln('    <info>wp-boost sync --upstream</info>   pull bleeding-edge from WordPress/agent-skills');
        $output->writeln('');
        $output->writeln('  <comment>Links</comment>');
        $output->writeln('    wp-boost    <info>https://github.com/huzaifaalmesbah/wp-boost</info>');
        $output->writeln('    Skills      <info>https://github.com/WordPress/agent-skills</info>');
        $output->writeln('');
        $output->writeln('  <comment>Skill content by WordPress/agent-skills · GPL-2.0-or-later · ♥</comment>');
    }

    /**
     * Resolve a list of items — from explicit CLI flags, --yes defaults, or interactive prompt.
     *
     * Uses maplephp/prompts for cross-platform interactive selection (works on Windows, macOS, Linux).
     *
     * @param array<string,string> $choices  key => label map
     * @param array<int,string>     $defaults default selection (keys)
     * @return array<int,string>     selected keys
     */
    private function resolveList(string $explicit, array $choices, array $defaults, string $label, bool $yes, OutputInterface $output): array
    {
        // 1. Explicit CLI flags — skip prompts entirely
        if ($explicit !== '') {
            $requested = array_filter(array_map('trim', explode(',', $explicit)), fn ($v) => $v !== '');
            $valid = [];
            $unknown = [];
            foreach ($requested as $name) {
                if (isset($choices[$name])) {
                    $valid[] = $name;
                } else {
                    $unknown[] = $name;
                }
            }
            if ($unknown !== []) {
                $output->writeln(sprintf(
                    '<comment>Ignoring unknown value(s): %s. Valid: %s</comment>',
                    implode(', ', $unknown),
                    implode(', ', array_keys($choices)),
                ));
            }
            return array_values(array_unique($valid));
        }

        // 2. --yes flag — use detected/recommended defaults
        if ($yes) {
            return $defaults;
        }

        // 3. Interactive prompt using maplephp/prompts (cross-platform)
        return $this->interactiveSelect($choices, $defaults, $label, $output);
    }

    /**
     * Interactive selection using maplephp/prompts.
     *
     * Displays numbered options with default markers and accepts comma-separated numbers.
     * Works natively on Windows, macOS, and Linux — no stty/POSIX required.
     *
     * @param array<string,string> $choices  key => display label
     * @param array<int,string>     $defaults default keys
     * @return array<int,string>     selected keys
     */
    private function interactiveSelect(array $choices, array $defaults, string $label, OutputInterface $output): array
    {
        $output->writeln('');
        $output->writeln(sprintf('  <question>%s</question>', $label));
        $output->writeln('  Enter numbers separated by commas (e.g. 1,3,5). Press Enter for defaults.');
        $output->writeln('');

        $index = [];
        $i = 1;
        $defaultNums = [];
        foreach ($choices as $key => $text) {
            $marker = in_array($key, $defaults, true) ? '*' : ' ';
            $output->writeln(sprintf('    %s %2d. %s', $marker, $i, $text));
            $index[$i] = $key;
            if (in_array($key, $defaults, true)) {
                $defaultNums[] = $i;
            }
            $i++;
        }

        $output->writeln('');
        $output->writeln('    * = recommended default');
        $output->writeln('');

        $defaultStr = implode(',', $defaultNums);

        // Use maplephp/prompts for a clean text input with validation
        $prompt = new Prompt();
        $prompt->set([
            'selection' => [
                'type' => 'list',
                'message' => 'Your choice',
                'default' => $defaultStr,
                'validate' => [
                    'length' => [0, 500],
                ],
            ],
        ]);

        try {
            $result = $prompt->prompt();
            $input = isset($result['selection']) ? trim((string) $result['selection']) : '';
        } catch (\Throwable $e) {
            // Fallback: read directly from STDIN if prompts library fails
            $output->write(sprintf('  Your choice [<comment>%s</comment>]: ', $defaultStr));
            $input = trim((string) fgets(STDIN));
        }

        // Empty input = accept defaults
        if ($input === '') {
            return $defaults;
        }

        // Parse comma-separated numbers
        $selected = [];
        foreach (array_map('trim', explode(',', $input)) as $num) {
            $n = (int) $num;
            if (isset($index[$n])) {
                $selected[] = $index[$n];
            }
        }

        return $selected !== [] ? array_values(array_unique($selected)) : $defaults;
    }

    /**
     * @param array<int,string> $agents
     * @param array<int,string> $skills
     */
    private function writeLock(string $projectRoot, array $agents, array $skills, OutputInterface $output): void
    {
        $lock = [
            'version' => 1,
            'installedAt' => date(DATE_ATOM),
            'agents' => array_values($agents),
            'skills' => array_values($skills),
            'bundle' => BundleMeta::read(Paths::bundledSkillsDir()),
        ];
        $lockPath = $projectRoot . '/wp-boost.lock.json';
        $bytes = @file_put_contents($lockPath, json_encode($lock, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        if ($bytes === false) {
            $output->writeln('<comment>Warning: could not write ' . $lockPath . ' (check permissions)</comment>');
        }
    }
}