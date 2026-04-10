<?php

declare(strict_types=1);

namespace Scafera\Log\Tests;

use PHPUnit\Framework\TestCase;
use Scafera\Log\StreamLogger;

final class StreamLoggerTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/scafera_log_test_' . uniqid();
        mkdir($this->tempDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $files = glob($this->tempDir . '/*');

        foreach ($files as $file) {
            unlink($file);
        }

        rmdir($this->tempDir);
    }

    public function testWritesJsonLineWithEventPromoted(): void
    {
        $logger = new StreamLogger($this->tempDir, 'test');
        $logger->info('Order placed', ['event' => 'order.created', 'orderId' => 42]);

        $logFile = $this->tempDir . '/test.log';
        $this->assertFileExists($logFile);

        $entry = json_decode(file_get_contents($logFile), true);
        $this->assertSame('info', $entry['level']);
        $this->assertSame('Order placed', $entry['message']);
        $this->assertSame('order.created', $entry['event']);
        $this->assertSame(['orderId' => 42], $entry['context']);
        $this->assertArrayHasKey('timestamp', $entry);
    }

    public function testAppendsMultipleEntries(): void
    {
        $logger = new StreamLogger($this->tempDir, 'test');
        $logger->info('First', ['event' => 'test.first']);
        $logger->info('Second', ['event' => 'test.second']);

        $lines = array_filter(explode("\n", file_get_contents($this->tempDir . '/test.log')));
        $this->assertCount(2, $lines);
    }

    public function testEventExtractedFromContextAndRemainingContextPreserved(): void
    {
        $logger = new StreamLogger($this->tempDir, 'test');
        $logger->info('Test', ['event' => 'test.event', 'key1' => 'val1', 'key2' => 'val2']);

        $entry = json_decode(file_get_contents($this->tempDir . '/test.log'), true);
        $this->assertSame('test.event', $entry['event']);
        $this->assertSame(['key1' => 'val1', 'key2' => 'val2'], $entry['context']);
        $this->assertArrayNotHasKey('event', $entry['context'] ?? []);
    }

    public function testContextOmittedWhenEmptyAfterEventExtraction(): void
    {
        $logger = new StreamLogger($this->tempDir, 'test');
        $logger->info('Test', ['event' => 'test.event']);

        $entry = json_decode(file_get_contents($this->tempDir . '/test.log'), true);
        $this->assertSame('test.event', $entry['event']);
        $this->assertArrayNotHasKey('context', $entry);
    }

    public function testExceptionContextSerialized(): void
    {
        $logger = new StreamLogger($this->tempDir, 'test');
        $exception = new \RuntimeException('Something broke', 42);
        $logger->error('Failed', ['event' => 'test.exception', 'exception' => $exception]);

        $entry = json_decode(file_get_contents($this->tempDir . '/test.log'), true);
        $ctx = $entry['context']['exception'];
        $this->assertSame(\RuntimeException::class, $ctx['class']);
        $this->assertSame('Something broke', $ctx['message']);
        $this->assertSame(42, $ctx['code']);
        $this->assertArrayHasKey('file', $ctx);
        $this->assertArrayHasKey('line', $ctx);
    }

    public function testAllPsr3LevelsAccepted(): void
    {
        $logger = new StreamLogger($this->tempDir, 'test');
        $levels = ['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency'];

        foreach ($levels as $level) {
            $logger->$level('Test message', ['event' => 'test.level']);
        }

        $lines = array_filter(explode("\n", file_get_contents($this->tempDir . '/test.log')));
        $this->assertCount(8, $lines);

        foreach ($lines as $i => $line) {
            $entry = json_decode($line, true);
            $this->assertSame($levels[$i], $entry['level']);
        }
    }

    public function testStringableMessageCastToString(): void
    {
        $logger = new StreamLogger($this->tempDir, 'test');
        $message = new class implements \Stringable {
            public function __toString(): string
            {
                return 'stringable message';
            }
        };

        $logger->info($message, ['event' => 'test.stringable']);

        $entry = json_decode(file_get_contents($this->tempDir . '/test.log'), true);
        $this->assertSame('stringable message', $entry['message']);
    }

    public function testEnvironmentDeterminesFilename(): void
    {
        $logger = new StreamLogger($this->tempDir, 'prod');
        $logger->info('Test', ['event' => 'test.env']);

        $this->assertFileExists($this->tempDir . '/prod.log');
        $this->assertFileDoesNotExist($this->tempDir . '/test.log');
    }

    public function testThrowsOnWriteFailure(): void
    {
        $logger = new StreamLogger('/nonexistent/path', 'test');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Failed to write log entry');

        $logger->info('Test', ['event' => 'test.fail']);
    }

    public function testObjectInContextSerializedToClassName(): void
    {
        $logger = new StreamLogger($this->tempDir, 'test');
        $logger->info('Test', ['event' => 'test.object', 'obj' => new \stdClass()]);

        $entry = json_decode(file_get_contents($this->tempDir . '/test.log'), true);
        $this->assertSame('stdClass', $entry['context']['obj']);
    }

    public function testStringableInContextCastToString(): void
    {
        $logger = new StreamLogger($this->tempDir, 'test');
        $stringable = new class implements \Stringable {
            public function __toString(): string
            {
                return 'rendered';
            }
        };

        $logger->info('Test', ['event' => 'test.ctx', 'val' => $stringable]);

        $entry = json_decode(file_get_contents($this->tempDir . '/test.log'), true);
        $this->assertSame('rendered', $entry['context']['val']);
    }

    public function testNestedArrayInContext(): void
    {
        $logger = new StreamLogger($this->tempDir, 'test');
        $logger->info('Test', ['event' => 'test.nested', 'data' => ['a' => 1, 'b' => ['c' => 2]]]);

        $entry = json_decode(file_get_contents($this->tempDir . '/test.log'), true);
        $this->assertSame(['a' => 1, 'b' => ['c' => 2]], $entry['context']['data']);
    }
}
