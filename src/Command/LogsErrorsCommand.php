<?php

declare(strict_types=1);

namespace Scafera\Log\Command;

use Scafera\Kernel\Console\Attribute\AsCommand;
use Scafera\Kernel\Console\Command;
use Scafera\Kernel\Console\Input;
use Scafera\Kernel\Console\Output;

#[AsCommand('logs:errors', description: 'Show log entries with severity >= error')]
final class LogsErrorsCommand extends Command
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
        $this->addOpt('scope', description: 'Filter by scope (app or framework)');
        $this->addFlag('json', description: 'Output as JSON');
    }

    protected function handle(Input $input, Output $output): int
    {
        $limit = (int) $input->option('limit');
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

        $results = [];

        foreach (LogReader::read($logFile) as $entry) {
            $level = $entry['level'] ?? '';

            if (!LogReader::severityAtLeast($level, 'error')) {
                continue;
            }

            if (!LogReader::matchesScope($entry, $scope)) {
                continue;
            }

            $results[] = $entry;

            if (count($results) >= $limit) {
                break;
            }
        }

        if ($asJson) {
            $output->writeln(json_encode([
                'meta' => ['command' => 'logs:errors', 'env' => $this->environment, 'file' => $logFile],
                'data' => $results,
            ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
            return 0;
        }

        if ($results === []) {
            $output->info('No error-level entries found.');
            return 0;
        }

        foreach ($results as $entry) {
            LogsFilterCommand::renderEntry($output, $entry);
        }

        return 0;
    }
}
