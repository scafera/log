<?php

declare(strict_types=1);

namespace Scafera\Log\Command;

use Scafera\Kernel\Console\Attribute\AsCommand;
use Scafera\Kernel\Console\Command;
use Scafera\Kernel\Console\Input;
use Scafera\Kernel\Console\Output;

#[AsCommand('logs:recent', description: 'Show the latest log entries')]
final class LogsRecentCommand extends Command
{
    public function __construct(
        private readonly string $logsDir,
        private readonly string $environment,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOpt('limit', description: 'Maximum entries to display', default: '50');
        $this->addOpt('level', description: 'Filter by severity level');
        $this->addFlag('json', description: 'Output as JSON');
    }

    protected function handle(Input $input, Output $output): int
    {
        $limit = (int) $input->option('limit');
        $level = $input->option('level');
        $asJson = $input->option('json');
        $logFile = LogReader::logFile($this->logsDir, $this->environment);

        if (!is_file($logFile)) {
            $output->warning(sprintf('Log file not found: %s', $logFile));
            return 0;
        }

        // Collect last N entries using a sliding window
        $recent = [];

        foreach (LogReader::read($logFile) as $entry) {
            if ($level !== null && ($entry['level'] ?? '') !== $level) {
                continue;
            }

            $recent[] = $entry;

            if (count($recent) > $limit) {
                array_shift($recent);
            }
        }

        if ($asJson) {
            $output->writeln(json_encode([
                'meta' => ['command' => 'logs:recent', 'env' => $this->environment, 'file' => $logFile],
                'data' => $recent,
            ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
            return 0;
        }

        if ($recent === []) {
            $output->info('No log entries found.');
            return 0;
        }

        foreach ($recent as $entry) {
            LogsFilterCommand::renderEntry($output, $entry);
        }

        return 0;
    }
}
