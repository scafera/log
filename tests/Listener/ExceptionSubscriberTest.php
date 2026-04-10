<?php

declare(strict_types=1);

namespace Scafera\Log\Tests\Listener;

use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Scafera\Log\Listener\ExceptionSubscriber;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Event\ConsoleErrorEvent;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;

final class ExceptionSubscriberTest extends TestCase
{
    private array $logCalls = [];
    private ExceptionSubscriber $subscriber;

    protected function setUp(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->method('log')->willReturnCallback(function (string $level, string $message, array $context) {
            $this->logCalls[] = ['level' => $level, 'message' => $message, 'context' => $context];
        });
        $logger->method('error')->willReturnCallback(function (string $message, array $context) {
            $this->logCalls[] = ['level' => 'error', 'message' => $message, 'context' => $context];
        });

        $this->subscriber = new ExceptionSubscriber($logger);
    }

    public function testLogs5xxAsError(): void
    {
        $event = $this->createHttpEvent(new \RuntimeException('Something broke'), '/api/orders', 'POST');

        $this->subscriber->onKernelException($event);

        $this->assertCount(1, $this->logCalls);
        $this->assertSame('error', $this->logCalls[0]['level']);
        $this->assertSame('Something broke', $this->logCalls[0]['message']);
        $this->assertSame('framework.http.error', $this->logCalls[0]['context']['event']);
        $this->assertSame('POST', $this->logCalls[0]['context']['method']);
        $this->assertSame('/api/orders', $this->logCalls[0]['context']['path']);
        $this->assertSame(500, $this->logCalls[0]['context']['status']);
        $this->assertInstanceOf(\RuntimeException::class, $this->logCalls[0]['context']['exception']);
    }

    public function testLogs4xxAsWarning(): void
    {
        $event = $this->createHttpEvent(new NotFoundHttpException('Route not found'), '/nonexistent', 'GET');

        $this->subscriber->onKernelException($event);

        $this->assertCount(1, $this->logCalls);
        $this->assertSame('warning', $this->logCalls[0]['level']);
        $this->assertSame('framework.http.error', $this->logCalls[0]['context']['event']);
        $this->assertSame(404, $this->logCalls[0]['context']['status']);
    }

    public function testLogsConsoleError(): void
    {
        $command = new Command('app:test');
        $error = new \RuntimeException('Command failed');
        $event = new ConsoleErrorEvent(new ArrayInput([]), new NullOutput(), $error, $command);

        $this->subscriber->onConsoleError($event);

        $this->assertCount(1, $this->logCalls);
        $this->assertSame('error', $this->logCalls[0]['level']);
        $this->assertSame('Command failed', $this->logCalls[0]['message']);
        $this->assertSame('framework.console.error', $this->logCalls[0]['context']['event']);
        $this->assertSame('app:test', $this->logCalls[0]['context']['command']);
        $this->assertInstanceOf(\RuntimeException::class, $this->logCalls[0]['context']['exception']);
    }

    public function testDoesNotSetResponseOnKernelException(): void
    {
        $event = $this->createHttpEvent(new \RuntimeException('test'), '/', 'GET');

        $this->subscriber->onKernelException($event);

        $this->assertFalse($event->hasResponse());
    }

    public function testSubscribedEventsHasCorrectPriorities(): void
    {
        $events = ExceptionSubscriber::getSubscribedEvents();

        $this->assertSame(['onKernelException', 1], $events[KernelEvents::EXCEPTION]);
        $this->assertSame(['onConsoleError', -127], $events['console.error']);
    }

    private function createHttpEvent(\Throwable $throwable, string $path, string $method): ExceptionEvent
    {
        $kernel = $this->createMock(HttpKernelInterface::class);
        $request = Request::create($path, $method);

        return new ExceptionEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $throwable);
    }
}
