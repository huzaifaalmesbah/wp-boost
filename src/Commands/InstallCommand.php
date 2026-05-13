<?php

declare(strict_types=1);

namespace Huzaifa\WpBoost\Commands;

use Laravel\Prompts\MultiSelectPrompt;
use Laravel\Prompts\Prompt;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;
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
 * Uses laravel/prompts for beautiful space-toggle multiselect on macOS/Linux.
 * On Windows (or non-interactive terminals), falls back to Symfony ChoiceQuestion
 * with comma-separated input — matching exactly how Laravel Boost handles Windows.
 */
#[AsCommand(name: 'install', description: 'Install WordPress agent skills for your selected AI agents.')]
final class InstallCommand extends Command
{
    /**
     * Whether the laravel/prompts fallback has been configured for this process.
     * Done once per process to register the Symfony-based fallback for Windows
     * and non-interactive terminals, mirroring Laravel's own ConfiguresPrompts trait.
     */
    private static bool $fallbackConfigured = false;

    /**
     * Register the laravel/prompts fallback for Windows and non-interactive environments.
     *
     * This mirrors Laravel's ConfiguresPrompts trait: when prompts can't use TTY
     * (Windows, pipes, CI), it falls back to Symfony ChoiceQuestion which works everywhere.
     */
    private function configurePromptsFallback(InputInterface $input, OutputInterface $output): void
    {
        if (self::$fallbackConfigured) {
            return;
        }
        self::$fallbackConfigured = true;

        // Tell laravel/prompts where to write output
        Prompt::setOutput($output);

        // Fall back on Windows (no stty) and non-interactive terminals
        Prompt::fallbackWhen(PHP_OS_FAMILY === 'Windows' || ! $input->isInteractive());

        // Register the multiselect fallback: use Symfony ChoiceQuestion
        // This is exactly what Laravel does in ConfiguresPrompts::configurePrompts()
        MultiSelectPrompt::fallbackUsing(function (MultiSelectPrompt $prompt) use ($input, $output): array {
            return $this->symfonyMultiselect(
                $prompt->options,
                $prompt->default,
                $prompt->label,
                $input,
                $output,
            );
        });
    }

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
        // Register fallbacks BEFORE any prompts are shown
        $this->configurePromptsFallback($input, $output);

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
            'Select AI agents to configure',
            'agents',
            (bool) $input->getOption('yes'),
            $input,
            $output,
        );

        if ($selectedAgents === []) {
            $output->writeln('<comment>No agents selected. Aborting.</comment>');
            return Command::FAILURE;
        }
        $skillChoices = [];
        foreach ($allSkills as $skill) {
            $skillChoices[$skill->name] = $skill->displayName;
        }

        $defaultSkills = array_values(array_intersect(
            array_keys($skillChoices),
            ProjectType::recommendedSkills($type),
        ));

        $selectedSkills = $this->resolveList(
            (string) $input->getOption('skills'),
            $skillChoices,
            $defaultSkills,
            'Select WordPress skills to install',
            'skills',
            (bool) $input->getOption('yes'),
            $input,
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
     * Display available skills with descriptions in a clean list before selection.
     *
     * @param array<string,\Huzaifa\WpBoost\Skills\Skill> $skills
     */
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
            $output->writeln('    · <info>' . $skill->displayName . '</info>');
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
     * Uses laravel/prompts (space-toggle) on macOS/Linux and falls back to
     * Symfony ChoiceQuestion (comma-separated) on Windows — same approach as Laravel Boost.
     *
     * @param array<string,string> $choices  key => label map
     * @param array<int,string>     $defaults default selection (keys)
     * @param string               $label    prompt question for interactive mode
     * @param string               $name     short name for non-interactive messages (e.g. 'agents', 'skills')
     * @return array<int,string>     selected keys
     */
    private function resolveList(
        string $explicit,
        array $choices,
        array $defaults,
        string $label,
        string $name,
        bool $yes,
        InputInterface $input,
        OutputInterface $output,
    ): array {
        // 1. Explicit CLI flags — skip prompts entirely
        if ($explicit !== '') {
            $requested = array_filter(array_map('trim', explode(',', $explicit)), fn ($v) => $v !== '');
            $valid = [];
            $unknown = [];
            foreach ($requested as $item) {
                if (isset($choices[$item])) {
                    $valid[] = $item;
                } else {
                    $unknown[] = $item;
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

        // 2. --yes flag or non-interactive terminal — use detected/recommended defaults
        if ($yes || ! $input->isInteractive()) {
            if (! $yes && ! $input->isInteractive()) {
                $output->writeln(sprintf(
                    '  <comment>Non-interactive terminal — using recommended %s</comment>',
                    $name,
                ));
            }
            return $defaults;
        }

        // 3. Interactive multiselect via laravel/prompts
        //    On macOS/Linux: space-toggle multiselect (beautiful TTY)
        //    On Windows/non-TTY: automatically falls back to Symfony ChoiceQuestion
        //    (registered in configurePromptsFallback)
        return $this->promptsMultiselect($choices, $defaults, $label);
    }

    /**
     * Interactive multiselect using laravel/prompts.
     *
     * On macOS/Linux with TTY: ↑↓ navigate, SPACE toggle, ENTER confirm
     * On Windows/non-TTY: falls back to Symfony ChoiceQuestion (comma-separated)
     *
     * @param array<string,string> $choices  key => display label
     * @param array<int,string>    $defaults default keys
     * @return array<int,string>    selected keys
     */
    private function promptsMultiselect(array $choices, array $defaults, string $label): array
    {
        // Mark recommended items with * in the label
        $recommendedSet = array_flip($defaults);
        $displayOptions = [];
        foreach ($choices as $key => $text) {
            if (isset($recommendedSet[$key])) {
                $displayOptions[$key] = $text . ' *';
            } else {
                $displayOptions[$key] = $text;
            }
        }

        // Default keys for pre-selection
        $defaultKeys = [];
        foreach ($defaults as $defaultKey) {
            if (isset($displayOptions[$defaultKey])) {
                $defaultKeys[] = $defaultKey;
            }
        }

        $selected = \Laravel\Prompts\multiselect(
            label: $label,
            options: $displayOptions,
            default: $defaultKeys,
            scroll: count($displayOptions),
            hint: '* = recommended  ·  ↑↓ navigate  ·  SPACE toggle  ·  ENTER confirm',
        );

        // Strip the ' *' suffix we added to recommended items
        $result = [];
        $flipped = array_flip($choices);
        foreach ($selected as $value) {
            $cleanValue = rtrim((string) $value, ' *');
            if (isset($flipped[$cleanValue])) {
                $result[] = $flipped[$cleanValue];
            } elseif (isset($choices[$value])) {
                $result[] = $value;
            }
        }

        return $result !== [] ? array_values(array_unique($result)) : $defaults;
    }

    /**
     * Symfony ChoiceQuestion fallback for Windows and non-interactive terminals.
     *
     * Displays numbered options with default markers and accepts comma-separated
     * index numbers or values. This is the same approach Laravel Boost uses.
     *
     * @param array<string,string> $choices  key => display label
     * @param array<int,string>    $defaults default keys
     * @return array<int,string>    selected keys
     */
    private function symfonyMultiselect(
        array $choices,
        array $defaults,
        string $label,
        InputInterface $input,
        OutputInterface $output,
    ): array {
        $output->writeln('');
        $output->writeln(sprintf('  <question>%s</question>', $label));
        $output->writeln('');

        // Show options with default markers
        $i = 0;
        foreach ($choices as $key => $text) {
            $marker = in_array($key, $defaults, true) ? '*' : ' ';
            $output->writeln(sprintf('    %s [<comment>%d</comment>] %s', $marker, $i, $text));
            $i++;
        }

        $output->writeln('');
        $output->writeln('    * = recommended  ·  Enter comma-separated numbers (e.g. 0,1,3)  ·  Enter = defaults');
        $output->writeln('');

        // Build the ChoiceQuestion
        $choiceValues = array_values($choices);
        $choiceKeys = array_keys($choices);
        $defaultIndices = [];
        foreach ($defaults as $defaultKey) {
            $idx = array_search($defaultKey, $choiceKeys, true);
            if ($idx !== false) {
                $defaultIndices[] = (string) $idx;
            }
        }

        $question = new ChoiceQuestion(
            '  Your choice',
            $choiceValues,
            implode(',', $defaultIndices),
        );
        $question->setMultiselect(true);
        $question->setErrorMessage('Invalid selection. Please enter valid numbers.');

        /** @var QuestionHelper $helper */
        $helper = $this->getHelper('question');
        $selectedValues = $helper->ask($input, $output, $question);

        // Map selected display labels back to keys
        $selected = [];
        $flipped = array_flip($choices);
        foreach ($selectedValues as $value) {
            if (isset($flipped[$value])) {
                $selected[] = $flipped[$value];
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