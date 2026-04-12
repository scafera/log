<?php

declare(strict_types=1);

namespace Scafera\Log\Tests;

use PHPUnit\Framework\TestCase;
use Scafera\Log\Validator\EventContextValidator;

final class EventContextValidatorTest extends TestCase
{
    private string $tempDir;
    private EventContextValidator $validator;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/scafera_validator_test_' . uniqid();
        mkdir($this->tempDir . '/src', 0777, true);
        $this->validator = new EventContextValidator();
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tempDir);
    }

    public function testPassesWithEventKey(): void
    {
        $this->writePhp("src/Service.php", <<<'PHP'
        <?php
        use Psr\Log\LoggerInterface;
        class Service {
            public function __construct(private LoggerInterface $logger) {}
            public function run(): void {
                $this->logger->info('Order placed', ['event' => 'order.created', 'id' => 1]);
            }
        }
        PHP);

        $violations = $this->validator->validate($this->tempDir);
        $this->assertSame([], $violations);
    }

    public function testFailsWhenEventKeyMissing(): void
    {
        $this->writePhp("src/Service.php", <<<'PHP'
        <?php
        use Psr\Log\LoggerInterface;
        class Service {
            public function __construct(private LoggerInterface $logger) {}
            public function run(): void {
                $this->logger->info('Order placed', ['orderId' => 1]);
            }
        }
        PHP);

        $violations = $this->validator->validate($this->tempDir);
        $this->assertCount(1, $violations);
        $this->assertStringContainsString('missing \'event\' key', $violations[0]);
    }

    public function testFailsWhenNoContextAtAll(): void
    {
        $this->writePhp("src/Service.php", <<<'PHP'
        <?php
        use Psr\Log\LoggerInterface;
        class Service {
            public function __construct(private LoggerInterface $logger) {}
            public function run(): void {
                $this->logger->info('Order placed');
            }
        }
        PHP);

        $violations = $this->validator->validate($this->tempDir);
        $this->assertCount(1, $violations);
        $this->assertStringContainsString('missing \'event\' key', $violations[0]);
    }

    public function testFailsWithInvalidEventFormat(): void
    {
        $this->writePhp("src/Service.php", <<<'PHP'
        <?php
        use Psr\Log\LoggerInterface;
        class Service {
            public function __construct(private LoggerInterface $logger) {}
            public function run(): void {
                $this->logger->info('Order placed', ['event' => 'Order_Created']);
            }
        }
        PHP);

        $violations = $this->validator->validate($this->tempDir);
        $this->assertCount(1, $violations);
        $this->assertStringContainsString('does not match required format', $violations[0]);
        $this->assertStringContainsString('Order_Created', $violations[0]);
    }

    public function testPassesWithNoLoggerCalls(): void
    {
        $this->writePhp("src/Service.php", <<<'PHP'
        <?php
        class Service {
            public function run(): void {
                echo 'hello';
            }
        }
        PHP);

        $violations = $this->validator->validate($this->tempDir);
        $this->assertSame([], $violations);
    }

    public function testSkipsFilesWithoutLoggerImport(): void
    {
        $this->writePhp("src/Service.php", <<<'PHP'
        <?php
        class Service {
            public function run(): void {
                $this->logger->info('test');
            }
        }
        PHP);

        $violations = $this->validator->validate($this->tempDir);
        $this->assertSame([], $violations);
    }

    public function testValidatesAllPsr3Methods(): void
    {
        $this->writePhp("src/Service.php", <<<'PHP'
        <?php
        use Psr\Log\LoggerInterface;
        class Service {
            public function __construct(private LoggerInterface $logger) {}
            public function run(): void {
                $this->logger->debug('test');
                $this->logger->warning('test');
                $this->logger->error('test');
            }
        }
        PHP);

        $violations = $this->validator->validate($this->tempDir);
        $this->assertCount(3, $violations);
    }

    public function testPassesWithDoubleQuotedEventKey(): void
    {
        $this->writePhp("src/Service.php", <<<'PHP'
        <?php
        use Psr\Log\LoggerInterface;
        class Service {
            public function __construct(private LoggerInterface $logger) {}
            public function run(): void {
                $this->logger->info("Order placed", ["event" => "order.created"]);
            }
        }
        PHP);

        $violations = $this->validator->validate($this->tempDir);
        $this->assertSame([], $violations);
    }

    public function testPassesWithMultiLineCall(): void
    {
        $this->writePhp("src/Service.php", <<<'PHP'
        <?php
        use Psr\Log\LoggerInterface;
        class Service {
            public function __construct(private LoggerInterface $logger) {}
            public function run(): void {
                $this->logger->info(
                    'Order placed',
                    [
                        'event' => 'order.created',
                        'orderId' => 1,
                    ],
                );
            }
        }
        PHP);

        $violations = $this->validator->validate($this->tempDir);
        $this->assertSame([], $violations);
    }

    public function testFailsOnMultiLineCallMissingEvent(): void
    {
        $this->writePhp("src/Service.php", <<<'PHP'
        <?php
        use Psr\Log\LoggerInterface;
        class Service {
            public function __construct(private LoggerInterface $logger) {}
            public function run(): void {
                $this->logger->info(
                    'Order placed',
                    [
                        'orderId' => 1,
                    ],
                );
            }
        }
        PHP);

        $violations = $this->validator->validate($this->tempDir);
        $this->assertCount(1, $violations);
        $this->assertStringContainsString('missing \'event\' key', $violations[0]);
    }

    public function testVariableContextIsSkippedAsDocumentedLimitation(): void
    {
        // Variable-context logger calls cannot be inspected statically.
        // The validator silently skips them rather than reporting false positives —
        // this test pins that documented behaviour (see class-level doc block).
        $this->writePhp("src/Service.php", <<<'PHP'
        <?php
        use Psr\Log\LoggerInterface;
        class Service {
            public function __construct(private LoggerInterface $logger) {}
            public function run(): void {
                $context = ['event' => 'order.created', 'id' => 1];
                $this->logger->info('Order placed', $context);
            }
        }
        PHP);

        $violations = $this->validator->validate($this->tempDir);
        $this->assertSame([], $violations);
    }

    public function testReturnsCorrectName(): void
    {
        $this->assertSame('EventContextValidator', $this->validator->getName());
    }

    public function testReturnsEmptyWhenNoSrcDir(): void
    {
        $emptyDir = sys_get_temp_dir() . '/scafera_no_src_' . uniqid();
        mkdir($emptyDir);

        $violations = $this->validator->validate($emptyDir);
        $this->assertSame([], $violations);

        rmdir($emptyDir);
    }

    private function writePhp(string $relativePath, string $content): void
    {
        $path = $this->tempDir . '/' . $relativePath;
        $dir = dirname($path);

        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        file_put_contents($path, $content);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $file) {
            if ($file->isDir()) {
                rmdir($file->getPathname());
            } else {
                unlink($file->getPathname());
            }
        }

        rmdir($dir);
    }
}
