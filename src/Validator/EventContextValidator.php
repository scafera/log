<?php

declare(strict_types=1);

namespace Scafera\Log\Validator;

use Scafera\Kernel\Contract\ValidatorInterface;

final class EventContextValidator implements ValidatorInterface
{
    private const EVENT_PATTERN = '/^[a-z][a-z0-9]*(\.[a-z][a-z0-9]*)+$/';

    private const LOGGER_METHODS = [
        'debug', 'info', 'notice', 'warning',
        'error', 'critical', 'alert', 'emergency',
    ];

    public function getName(): string
    {
        return 'EventContextValidator';
    }

    public function validate(string $projectDir): array
    {
        $srcDir = $projectDir . '/src';

        if (!is_dir($srcDir)) {
            return [];
        }

        $violations = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($srcDir, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $fileViolations = $this->validateFile($file->getPathname(), $projectDir);
            $violations = [...$violations, ...$fileViolations];
        }

        return $violations;
    }

    /** @return list<string> */
    private function validateFile(string $filePath, string $projectDir): array
    {
        $content = file_get_contents($filePath);

        if ($content === false) {
            return [];
        }

        if (!str_contains($content, 'LoggerInterface')) {
            return [];
        }

        $violations = [];
        $lines = explode("\n", $content);
        $relativePath = str_starts_with($filePath, $projectDir)
            ? ltrim(substr($filePath, strlen($projectDir)), '/')
            : $filePath;

        foreach ($lines as $lineNumber => $line) {
            $trimmed = trim($line);

            foreach (self::LOGGER_METHODS as $method) {
                if (!str_contains($trimmed, '->' . $method . '(')) {
                    continue;
                }

                if (!str_contains($trimmed, "'event'") && !str_contains($trimmed, '"event"')) {
                    $violations[] = sprintf(
                        '%s:%d — logger call missing \'event\' key in context',
                        $relativePath,
                        $lineNumber + 1,
                    );
                    continue;
                }

                $eventValue = $this->extractEventValue($trimmed);

                if ($eventValue !== null && !preg_match(self::EVENT_PATTERN, $eventValue)) {
                    $violations[] = sprintf(
                        '%s:%d — event \'%s\' does not match required format (lowercase dot notation)',
                        $relativePath,
                        $lineNumber + 1,
                        $eventValue,
                    );
                }
            }
        }

        return $violations;
    }

    private function extractEventValue(string $line): ?string
    {
        if (preg_match("/['\"]event['\"]\s*=>\s*['\"]([^'\"]+)['\"]/", $line, $matches)) {
            return $matches[1];
        }

        return null;
    }
}
