<?php

/** generated from /home/tac/g/survos/survos/vendor/survos/maker-bundle/templates/skeleton/bundle/src/Bundle.tpl.php */

namespace Survos\FlickrBundle;

use Survos\BarcodeBundle\Twig\BarcodeTwigExtension;
use Survos\FlickrBundle\Services\FlickrService;
use Survos\FlickrBundle\Twig\TwigExtension;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;
use Symfony\Component\VarExporter\Internal\Reference;

class SurvosFlickrBundle extends AbstractBundle
{
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $builder->autowire(FlickrService::class)
            ->setAutowired(true)
            ->setAutoconfigured(true)
            ->setArgument('$apiKey', $config['api_key'])
            ->setArgument('$secret', $config['secret'])
        ;

        $builder
            ->autowire('survos.flickr_twig', TwigExtension::class)
            ->addTag('twig.extension')
//            ->setArgument('$flickrService', new Reference(FlickrService::class))
        ;

    }

    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
            ->scalarNode('api_key')->defaultValue('')->end()
            ->scalarNode('secret')->defaultValue('')->end()
            ->end();
    }
}
