<?php

declare(strict_types=1);

namespace Scafera\Log;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

final class LogBundle extends AbstractBundle
{
    public function build(ContainerBuilder $builder): void
    {
        $builder->addCompilerPass(new DependencyInjection\DisableSymfonyErrorLoggingPass());
    }

    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $container->services()
            ->set(StreamLogger::class)
                ->args([
                    '%kernel.logs_dir%',
                    '%kernel.environment%',
                ])
                ->public()
            ->alias(LoggerInterface::class, StreamLogger::class)
                ->public()

            // Validator
            ->set(Validator\EventContextValidator::class)
                ->tag('scafera.validator')

            // CLI commands — tools
            ->set(Command\LogsStatsCommand::class)
                ->args(['%kernel.logs_dir%', '%kernel.environment%'])
                ->tag('console.command')
            ->set(Command\LogsFilterCommand::class)
                ->args(['%kernel.logs_dir%', '%kernel.environment%'])
                ->tag('console.command')

            // Framework error logging
            ->set(Listener\ExceptionSubscriber::class)
                ->args([service(LoggerInterface::class)])
                ->tag('kernel.event_subscriber')

            // CLI commands — operational
            ->set(Command\LogsErrorsCommand::class)
                ->args(['%kernel.logs_dir%', '%kernel.environment%'])
                ->tag('console.command')
            ->set(Command\LogsStatusCommand::class)
                ->args(['%kernel.logs_dir%', '%kernel.environment%'])
                ->tag('console.command');
    }
}
