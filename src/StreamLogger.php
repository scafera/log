<?php

declare(strict_types=1);

namespace Scafera\Log;

use Psr\Log\AbstractLogger;
use Symfony\Component\HttpFoundation\RequestStack;

final class StreamLogger extends AbstractLogger
{
    private readonly string $logFile;

    public function __construct(
        string $logsDir,
        string $environment,
        private readonly ?RequestStack $requestStack = null,
    ) {
        $this->logFile = $logsDir . '/' . $environment . '.log';
    }

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $entry = [
            'timestamp' => (new \DateTimeImmutable())->format(\DateTimeInterface::RFC3339_EXTENDED),
            'level' => $level,
            'message' => (string) $message,
        ];

        if (isset($context['event'])) {
            $entry['event'] = $context['event'];
            unset($context['event']);
        }

        $ip = $this->requestStack?->getCurrentRequest()?->getClientIp();

        if ($ip !== null) {
            $entry['ip'] = $ip;
        }

        if ($context !== []) {
            $entry['context'] = $this->sanitizeContext($context);
        }

        $line = json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";

        $result = @file_put_contents($this->logFile, $line, FILE_APPEND | LOCK_EX);

        if ($result === false) {
            throw new \RuntimeException(sprintf('Failed to write log entry to "%s".', $this->logFile));
        }
    }

    private function sanitizeContext(array $context): array
    {
        $sanitized = [];

        foreach ($context as $key => $value) {
            if ($key === 'exception' && $value instanceof \Throwable) {
                $sanitized[$key] = [
                    'class' => $value::class,
                    'message' => $value->getMessage(),
                    'code' => $value->getCode(),
                    'file' => $value->getFile(),
                    'line' => $value->getLine(),
                ];
            } elseif (is_scalar($value) || $value === null) {
                $sanitized[$key] = $value;
            } elseif ($value instanceof \Stringable) {
                $sanitized[$key] = (string) $value;
            } elseif (is_array($value)) {
                $sanitized[$key] = $this->sanitizeContext($value);
            } elseif (is_object($value)) {
                $sanitized[$key] = $value::class;
            } else {
                $sanitized[$key] = '[unsupported type]';
            }
        }

        return $sanitized;
    }
}
