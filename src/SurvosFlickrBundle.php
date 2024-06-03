<?php

/** generated from /home/tac/g/survos/survos/vendor/survos/maker-bundle/templates/skeleton/bundle/src/Bundle.tpl.php */

namespace Survos\FlickrBundle;

use Survos\FlickrBundle\Services\FlickrService;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

class SurvosFlickrBundle extends AbstractBundle
{
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $definition = $builder->autowire(FlickrService::class)
            ->setAutowired(true)
            ->setAutoconfigured(true)
            ->setArgument('$apiKey', $config['api_key'])
            ->setArgument('$secret', $config['secret'])
        ;

        // $builder->setParameter('survos_workflow.direction', $config['direction']);

        // twig classes

        /*
        $definition = $builder
        ->autowire('survos.barcode_twig', BarcodeTwigExtension::class)
        ->addTag('twig.extension');

        $definition->setArgument('$widthFactor', $config['widthFactor']);
        $definition->setArgument('$height', $config['height']);
        $definition->setArgument('$foregroundColor', $config['foregroundColor']);
        */
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
