<?php

declare(strict_types=1);

namespace Scafera\Log\Command;

use Scafera\Kernel\Console\Attribute\AsCommand;
use Scafera\Kernel\Console\Command;
use Scafera\Kernel\Console\Input;
use Scafera\Kernel\Console\Output;

#[AsCommand('logs:clear', description: 'Clear the current log file')]
final class LogsClearCommand extends Command
{
    public function __construct(
        private readonly string $logsDir,
        private readonly string $environment,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addFlag('yes', shortcut: 'y', description: 'Skip confirmation prompt');
    }

    protected function handle(Input $input, Output $output): int
    {
        $logFile = LogReader::logFile($this->logsDir, $this->environment);

        if (!is_file($logFile)) {
            $output->info(sprintf('Log file not found: %s', $logFile));
            return 0;
        }

        $size = filesize($logFile);
        $sizeFormatted = $this->formatBytes($size !== false ? $size : 0);

        if (!$input->option('yes')) {
            if (!$output->confirm(sprintf('Clear %s (%s)?', $logFile, $sizeFormatted), false)) {
                $output->info('Cancelled.');
                return 0;
            }
        }

        file_put_contents($logFile, '');

        $output->success(sprintf('Cleared %s (%s)', $logFile, $sizeFormatted));

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
