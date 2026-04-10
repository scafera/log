<?php

declare(strict_types=1);

namespace Scafera\Log\Command;

use Scafera\Kernel\Console\Attribute\AsCommand;
use Scafera\Kernel\Console\Command;
use Scafera\Kernel\Console\Input;
use Scafera\Kernel\Console\Output;

#[AsCommand('logs:status', description: 'Show operational log summary')]
final class LogsStatusCommand extends Command
{
    private const MAX_TOP_EVENTS = 5;
    private const MAX_RECENT_FAILURES = 5;

    public function __construct(
        private readonly string $logsDir,
        private readonly string $environment,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addFlag('json', description: 'Output as JSON');
    }

    protected function handle(Input $input, Output $output): int
    {
        $asJson = $input->option('json');
        $logFile = LogReader::logFile($this->logsDir, $this->environment);

        if (!is_file($logFile)) {
            $output->warning(sprintf('Log file not found: %s', $logFile));
            return 0;
        }

        $totalEntries = 0;
        $errorCount = 0;
        $warningCount = 0;
        $eventCounts = [];
        $recentFailures = [];
        $cutoff = (new \DateTimeImmutable('-24 hours'))->format(\DateTimeInterface::RFC3339_EXTENDED);

        foreach (LogReader::read($logFile) as $entry) {
            $totalEntries++;
            $event = $entry['event'] ?? '(no event)';
            $level = $entry['level'] ?? '';
            $timestamp = $entry['timestamp'] ?? '';

            $eventCounts[$event] = ($eventCounts[$event] ?? 0) + 1;

            if ($timestamp >= $cutoff) {
                if (LogReader::severityAtLeast($level, 'error')) {
                    $errorCount++;
                } elseif ($level === 'warning') {
                    $warningCount++;
                }
            }

            if (LogReader::severityAtLeast($level, 'error')) {
                $recentFailures[] = $entry;

                if (count($recentFailures) > self::MAX_RECENT_FAILURES) {
                    array_shift($recentFailures);
                }
            }
        }

        arsort($eventCounts);
        $topEvents = array_slice($eventCounts, 0, self::MAX_TOP_EVENTS, true);
        $recentFailures = array_reverse($recentFailures);

        $fileSize = filesize($logFile);
        $fileSizeFormatted = $this->formatBytes($fileSize !== false ? $fileSize : 0);

        if ($asJson) {
            $output->writeln(json_encode([
                'meta' => ['command' => 'logs:status', 'env' => $this->environment, 'file' => $logFile],
                'data' => [
                    'total_entries' => $totalEntries,
                    'file_size' => $fileSize,
                    'errors_24h' => $errorCount,
                    'warnings_24h' => $warningCount,
                    'top_events' => array_map(
                        fn(string $event, int $count) => ['event' => $event, 'count' => $count],
                        array_keys($topEvents),
                        array_values($topEvents),
                    ),
                    'recent_failures' => $recentFailures,
                ],
            ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
            return 0;
        }

        $output->writeln(sprintf('Environment: %s', $this->environment));
        $output->writeln(sprintf('Log file:    %s (%s, %s entries)', $logFile, $fileSizeFormatted, number_format($totalEntries)));
        $output->writeln('');
        $output->writeln(sprintf('Errors (last 24h):    %d', $errorCount));
        $output->writeln(sprintf('Warnings (last 24h):  %d', $warningCount));

        if ($topEvents !== []) {
            $output->writeln('');
            $output->writeln('Top events:');

            foreach ($topEvents as $event => $count) {
                $output->writeln(sprintf('  %-25s %s', $event, number_format($count)));
            }
        }

        if ($recentFailures !== []) {
            $output->writeln('');
            $output->writeln('Recent failures:');

            foreach ($recentFailures as $entry) {
                $timestamp = substr(str_replace('T', ' ', $entry['timestamp'] ?? ''), 11, 8);
                $output->writeln(sprintf(
                    '  %s  %-9s %-20s %s',
                    $timestamp,
                    $entry['level'] ?? '',
                    $entry['event'] ?? '',
                    $entry['message'] ?? '',
                ));
            }
        }

        return 0;
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }

        if ($bytes < 1048576) {
            return round($bytes / 1024, 1) . ' KB';
        }

        return round($bytes / 1048576, 1) . ' MB';
    }
}
