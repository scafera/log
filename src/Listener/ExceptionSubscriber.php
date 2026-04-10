<?php

declare(strict_types=1);

namespace Scafera\Log\Listener;

use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Event\ConsoleErrorEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;

final class ExceptionSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::EXCEPTION => ['onKernelException', 1],
            'console.error' => ['onConsoleError', -127],
        ];
    }

    public function onKernelException(ExceptionEvent $event): void
    {
        $throwable = $event->getThrowable();

        if ($throwable instanceof HttpExceptionInterface) {
            $status = $throwable->getStatusCode();
            $level = $status >= 500 ? 'error' : 'warning';
        } else {
            $status = 500;
            $level = 'error';
        }

        try {
            $this->logger->log($level, $throwable->getMessage(), [
                'event' => 'framework.http.error',
                'exception' => $throwable,
                'method' => $event->getRequest()->getMethod(),
                'path' => $event->getRequest()->getPathInfo(),
                'status' => $status,
            ]);
        } catch (\Throwable) {
            // Logging failure must not replace the original exception.
        }
    }

    public function onConsoleError(ConsoleErrorEvent $event): void
    {
        $error = $event->getError();

        try {
            $this->logger->error($error->getMessage(), [
                'event' => 'framework.console.error',
                'exception' => $error,
                'command' => $event->getCommand()?->getName() ?? '(unknown)',
                'exit_code' => $event->getExitCode(),
            ]);
        } catch (\Throwable) {
            // Logging failure must not replace the original exception.
        }
    }
}
