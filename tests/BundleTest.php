<?php

declare(strict_types=1);

namespace Survos\FlickrBundle\Tests;

use PHPUnit\Framework\TestCase;
use Survos\FlickrBundle\SurvosFlickrBundle;
use Survos\FlickrBundle\Services\FlickrService;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use Symfony\Component\Console\Tester\CommandTester;

final class BundleTest extends TestCase
{
    public function testContainerAndCommandsWithoutSecurityBundle(): void
    {
        $kernel = new FlickrTestKernel('test', true);
        try {
            $kernel->boot();
            $application = new Application($kernel);
            $command = new CommandTester($application->find('survos:flickr:upload-file'));
            self::assertSame(0, $command->execute(['filename' => __FILE__, '--dry-run' => true]));
            self::assertStringContainsString('Local inputs are valid', $command->getDisplay());
            self::assertSame(2, $command->execute(['filename' => '/nonexistent', '--dry-run' => true]));
            $legacy = new CommandTester($application->find('survos:flickr:upload'));
            self::assertSame(1, $legacy->execute([]));
            self::assertStringContainsString('upload-file', $legacy->getDisplay());
            self::assertTrue($application->has('survos:flickr:import'));
        } finally {
            $kernel->shutdown();
        }
    }
}

final class FlickrTestKernel extends Kernel
{
    use MicroKernelTrait;

    /** @return iterable<\Symfony\Component\DependencyInjection\Kernel\BundleInterface> */
    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new SurvosFlickrBundle();
    }

    public function getProjectDir(): string
    {
        return __DIR__.'/cache/project';
    }

    public function getCacheDir(): string
    {
        return __DIR__.'/cache/kernel';
    }

    public function getLogDir(): string
    {
        return __DIR__.'/cache/log';
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', ['secret' => 'test', 'test' => true, 'http_method_override' => false]);
        $container->extension('survos_flickr', ['api_key' => 'test', 'secret' => 'test']);
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import(__DIR__.'/../config/routes.yaml');
    }
}
