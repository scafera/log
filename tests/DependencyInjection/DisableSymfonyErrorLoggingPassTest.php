<?php

declare(strict_types=1);

namespace Scafera\Log\Tests\DependencyInjection;

use PHPUnit\Framework\TestCase;
use Scafera\Log\DependencyInjection\DisableSymfonyErrorLoggingPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

final class DisableSymfonyErrorLoggingPassTest extends TestCase
{
    public function testNullifiesLoggerOnExceptionListener(): void
    {
        $container = new ContainerBuilder();
        $definition = new Definition('Symfony\Component\HttpKernel\EventListener\ErrorListener');
        $definition->setArguments(['error_controller', 'logger_service', false, [], []]);
        $container->setDefinition('exception_listener', $definition);

        (new DisableSymfonyErrorLoggingPass())->process($container);

        $this->assertNull($container->getDefinition('exception_listener')->getArgument(1));
    }

    public function testNullifiesLoggerOnConsoleErrorListener(): void
    {
        $container = new ContainerBuilder();
        $definition = new Definition('Symfony\Component\Console\EventListener\ErrorListener');
        $definition->setArguments(['logger_service']);
        $container->setDefinition('console.error_listener', $definition);

        (new DisableSymfonyErrorLoggingPass())->process($container);

        $this->assertNull($container->getDefinition('console.error_listener')->getArgument(0));
    }

    public function testHandlesMissingServicesGracefully(): void
    {
        $container = new ContainerBuilder();

        (new DisableSymfonyErrorLoggingPass())->process($container);

        $this->assertFalse($container->hasDefinition('exception_listener'));
        $this->assertFalse($container->hasDefinition('console.error_listener'));
    }
}
