<?php

/** generated from /home/tac/g/survos/survos/vendor/survos/maker-bundle/templates/skeleton/bundle/src/Bundle.tpl.php */

namespace Survos\FlickrBundle;

use Survos\BarcodeBundle\Twig\BarcodeTwigExtension;
use Survos\FlickrBundle\Services\FlickrService;
use Survos\FlickrBundle\Twig\TwigExtension;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

class SurvosFlickrBundle extends AbstractBundle
{
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $builder->autowire(FlickrService::class)
            ->setAutowired(true)
            ->setAutoconfigured(true)
            ->setArgument('$apiKey', $config['api_key'])
            ->setArgument('$secret', $config['secret'])
            ->setArgument('$cacheExpiration', $config['cache_expiration'])
            // this may have existed for the session?
//            ->setArgument(
//                '$requestStack',
//                new Reference('request_stack', ContainerInterface::NULL_ON_INVALID_REFERENCE)
//            )
            ->setArgument(
                '$security',
                new Reference('security.helper', ContainerInterface::NULL_ON_INVALID_REFERENCE)
            );

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
            ->scalarNode('cache_expiration')->defaultValue(3600)->end()
            ->end();
    }
}
