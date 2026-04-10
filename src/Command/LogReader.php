<?php

declare(strict_types=1);

namespace Scafera\Log\Command;

final class LogReader
{
    private const SEVERITY_ORDER = [
        'debug' => 0,
        'info' => 1,
        'notice' => 2,
        'warning' => 3,
        'error' => 4,
        'critical' => 5,
        'alert' => 6,
        'emergency' => 7,
    ];

    /**
     * @return \Generator<int, array<string, mixed>>
     */
    public static function read(string $logFile): \Generator
    {
        if (!is_file($logFile)) {
            return;
        }

        $handle = fopen($logFile, 'r');

        if ($handle === false) {
            return;
        }

        try {
            while (($line = fgets($handle)) !== false) {
                $line = trim($line);

                if ($line === '') {
                    continue;
                }

                $entry = json_decode($line, true);

                if (!is_array($entry)) {
                    continue;
                }

                yield $entry;
            }
        } finally {
            fclose($handle);
        }
    }

    public static function logFile(string $logsDir, string $environment): string
    {
        return $logsDir . '/' . $environment . '.log';
    }

    public static function severityAtLeast(string $level, string $threshold): bool
    {
        return (self::SEVERITY_ORDER[$level] ?? 0) >= (self::SEVERITY_ORDER[$threshold] ?? 0);
    }

    public static function isValidScope(?string $scope): bool
    {
        return $scope === null || $scope === 'app' || $scope === 'framework';
    }

    public static function matchesScope(array $entry, ?string $scope): bool
    {
        if ($scope === null) {
            return true;
        }

        $event = $entry['event'] ?? '';
        $isFramework = str_starts_with($event, 'framework.');

        return $scope === 'framework' ? $isFramework : !$isFramework;
    }
}
