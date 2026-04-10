<?php

declare(strict_types=1);

namespace Scafera\Log\DependencyInjection;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class DisableSymfonyErrorLoggingPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if ($container->hasDefinition('exception_listener')) {
            $container->getDefinition('exception_listener')->replaceArgument(1, null);
        }

        if ($container->hasDefinition('console.error_listener')) {
            $container->getDefinition('console.error_listener')->replaceArgument(0, null);
        }
    }
}
