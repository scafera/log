<?php

declare(strict_types=1);

namespace Scafera\Log\Command;

use Scafera\Kernel\Console\Attribute\AsCommand;
use Scafera\Kernel\Console\Command;
use Scafera\Kernel\Console\Input;
use Scafera\Kernel\Console\Output;

#[AsCommand('logs:stats', description: 'Aggregate log counts grouped by event')]
final class LogsStatsCommand extends Command
{
    public function __construct(
        private readonly string $logsDir,
        private readonly string $environment,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addFlag('by-level', description: 'Group by event and severity level');
        $this->addOpt('scope', description: 'Filter by scope (app or framework)');
        $this->addFlag('json', description: 'Output as JSON');
    }

    protected function handle(Input $input, Output $output): int
    {
        $byLevel = $input->option('by-level');
        $scope = $input->option('scope');
        $asJson = $input->option('json');
        $logFile = LogReader::logFile($this->logsDir, $this->environment);

        if (!LogReader::isValidScope($scope)) {
            $output->error('Invalid scope. Use "app" or "framework".');
            return 1;
        }

        if (!is_file($logFile)) {
            $output->warning(sprintf('Log file not found: %s', $logFile));
            return 0;
        }

        $counts = [];
        $levelCounts = [];

        foreach (LogReader::read($logFile) as $entry) {
            if (!LogReader::matchesScope($entry, $scope)) {
                continue;
            }

            $event = $entry['event'] ?? '(no event)';
            $level = $entry['level'] ?? 'unknown';

            if ($byLevel) {
                $key = $event . '|' . $level;
                $counts[$key] = ($counts[$key] ?? ['event' => $event, 'level' => $level, 'count' => 0]);
                $counts[$key]['count']++;
            } else {
                $counts[$event] = ($counts[$event] ?? ['event' => $event, 'count' => 0]);
                $counts[$event]['count']++;
                $levelCounts[$event][$level] = ($levelCounts[$event][$level] ?? 0) + 1;
            }
        }

        if (!$byLevel) {
            foreach ($counts as $event => &$row) {
                $levels = $levelCounts[$event];
                arsort($levels);
                $row['level'] = array_key_first($levels);
            }
            unset($row);
        }

        usort($counts, fn(array $a, array $b) => $b['count'] <=> $a['count']);

        if ($asJson) {
            $output->writeln(json_encode([
                'meta' => ['command' => 'logs:stats', 'env' => $this->environment, 'file' => $logFile],
                'data' => array_values($counts),
            ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
            return 0;
        }

        if ($counts === []) {
            $output->info('No log entries found.');
            return 0;
        }

        $output->table(
            ['Event', 'Level', 'Count'],
            array_map(fn(array $row) => [$row['event'], $row['level'], (string) $row['count']], $counts),
        );

        return 0;
    }
}
