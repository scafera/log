<?php

declare(strict_types=1);

namespace Scafera\Log\Tests;

use PHPUnit\Framework\TestCase;
use Scafera\Log\Command\LogReader;

final class LogReaderTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/scafera_reader_test_' . uniqid();
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

    public function testReadsJsonLines(): void
    {
        $logFile = $this->tempDir . '/test.log';
        file_put_contents($logFile, implode("\n", [
            json_encode(['level' => 'info', 'message' => 'first', 'event' => 'test.first']),
            json_encode(['level' => 'error', 'message' => 'second', 'event' => 'test.second']),
        ]) . "\n");

        $entries = iterator_to_array(LogReader::read($logFile));
        $this->assertCount(2, $entries);
        $this->assertSame('first', $entries[0]['message']);
        $this->assertSame('second', $entries[1]['message']);
    }

    public function testSkipsEmptyLinesAndInvalidJson(): void
    {
        $logFile = $this->tempDir . '/test.log';
        file_put_contents($logFile, implode("\n", [
            json_encode(['level' => 'info', 'message' => 'valid']),
            '',
            'not json',
            json_encode(['level' => 'error', 'message' => 'also valid']),
        ]) . "\n");

        $entries = iterator_to_array(LogReader::read($logFile));
        $this->assertCount(2, $entries);
    }

    public function testReturnsEmptyForMissingFile(): void
    {
        $entries = iterator_to_array(LogReader::read('/nonexistent/file.log'));
        $this->assertSame([], $entries);
    }

    public function testSeverityAtLeast(): void
    {
        $this->assertTrue(LogReader::severityAtLeast('error', 'error'));
        $this->assertTrue(LogReader::severityAtLeast('critical', 'error'));
        $this->assertTrue(LogReader::severityAtLeast('alert', 'error'));
        $this->assertTrue(LogReader::severityAtLeast('emergency', 'error'));
        $this->assertFalse(LogReader::severityAtLeast('warning', 'error'));
        $this->assertFalse(LogReader::severityAtLeast('info', 'error'));
        $this->assertFalse(LogReader::severityAtLeast('debug', 'error'));
    }

    public function testLogFileBuildsPath(): void
    {
        $this->assertSame('/var/log/dev.log', LogReader::logFile('/var/log', 'dev'));
        $this->assertSame('/var/log/prod.log', LogReader::logFile('/var/log', 'prod'));
    }
}
