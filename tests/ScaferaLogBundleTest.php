<?php

declare(strict_types=1);

namespace Scafera\Log\Tests;

use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Scafera\Log\ScaferaLogBundle;
use Scafera\Log\StreamLogger;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

final class ScaferaLogBundleTest extends TestCase
{
    public function testStreamLoggerIsRegistered(): void
    {
        $kernel = new TestLogKernel('test', true);
        $kernel->boot();

        $container = $kernel->getContainer();

        $this->assertTrue($container->has(StreamLogger::class));
        $this->assertInstanceOf(StreamLogger::class, $container->get(StreamLogger::class));
    }

    public function testLoggerInterfaceResolvesToStreamLogger(): void
    {
        $kernel = new TestLogKernel('test', true);
        $kernel->boot();

        $container = $kernel->getContainer();

        $this->assertTrue($container->has(LoggerInterface::class));
        $this->assertInstanceOf(StreamLogger::class, $container->get(LoggerInterface::class));
    }
}

/**
 * Minimal kernel that boots only FrameworkBundle + ScaferaLogBundle.
 */
final class TestLogKernel extends Kernel
{
    use MicroKernelTrait;

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new ScaferaLogBundle();
    }

    protected function configureContainer(ContainerConfigurator $c): void
    {
        $c->extension('framework', [
            'secret' => 'test',
            'test' => true,
        ]);
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
    }

    public function getCacheDir(): string
    {
        return sys_get_temp_dir() . '/scafera_log_bundle_test/cache';
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir() . '/scafera_log_bundle_test/log';
    }
}
