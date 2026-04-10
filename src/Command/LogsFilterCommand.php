<?php

declare(strict_types=1);

namespace Scafera\Log\Command;

use Scafera\Kernel\Console\Attribute\AsCommand;
use Scafera\Kernel\Console\Command;
use Scafera\Kernel\Console\Input;
use Scafera\Kernel\Console\Output;

#[AsCommand('logs:filter', description: 'Filter log entries by event, level, or text')]
final class LogsFilterCommand extends Command
{
    public function __construct(
        private readonly string $logsDir,
        private readonly string $environment,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArg('event', 'Filter by event name', required: false);
        $this->addOpt('level', description: 'Filter by severity level');
        $this->addOpt('search', description: 'Filter by text in message or context');
        $this->addOpt('limit', description: 'Maximum entries to display', default: '50');
        $this->addOpt('scope', description: 'Filter by scope (app or framework)');
        $this->addFlag('json', description: 'Output as JSON');
    }

    protected function handle(Input $input, Output $output): int
    {
        $event = $input->argument('event');
        $level = $input->option('level');
        $search = $input->option('search');
        $scope = $input->option('scope');
        $limit = (int) $input->option('limit');
        $asJson = $input->option('json');
        $logFile = LogReader::logFile($this->logsDir, $this->environment);

        if (!LogReader::isValidScope($scope)) {
            $output->error('Invalid scope. Use "app" or "framework".');
            return 1;
        }

        if ($event === null && $level === null && $search === null && $scope === null) {
            $output->error('At least one filter is required: event argument, --level, --search, or --scope.');
            return 1;
        }

        if (!is_file($logFile)) {
            $output->warning(sprintf('Log file not found: %s', $logFile));
            return 0;
        }

        $results = [];

        foreach (LogReader::read($logFile) as $entry) {
            if ($event !== null && ($entry['event'] ?? '') !== $event) {
                continue;
            }

            if ($level !== null && ($entry['level'] ?? '') !== $level) {
                continue;
            }

            if (!LogReader::matchesScope($entry, $scope)) {
                continue;
            }

            if ($search !== null) {
                $haystack = json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

                if (!str_contains($haystack, $search)) {
                    continue;
                }
            }

            $results[] = $entry;

            if (count($results) >= $limit) {
                break;
            }
        }

        if ($asJson) {
            $output->writeln(json_encode([
                'meta' => ['command' => 'logs:filter', 'env' => $this->environment, 'file' => $logFile],
                'data' => $results,
            ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
            return 0;
        }

        if ($results === []) {
            $output->info('No matching log entries found.');
            return 0;
        }

        foreach ($results as $entry) {
            self::renderEntry($output, $entry);
        }

        return 0;
    }

    public static function renderEntry(Output $output, array $entry): void
    {
        $timestamp = $entry['timestamp'] ?? '';
        $level = $entry['level'] ?? '';
        $event = $entry['event'] ?? '';
        $message = $entry['message'] ?? '';
        $context = $entry['context'] ?? null;

        $short = substr($timestamp, 0, 19);
        $short = str_replace('T', ' ', $short);

        $contextStr = $context !== null ? '  ' . json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : '';

        $output->writeln(sprintf(
            '%s  %-9s %-20s %s%s',
            $short,
            $level,
            $event,
            $message,
            $contextStr,
        ));
    }
}
