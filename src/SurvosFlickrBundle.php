<?php

declare(strict_types=1);

namespace Survos\FlickrBundle;

use Survos\FlickrBundle\Services\FlickrService;
use Survos\FlickrBundle\Twig\TwigExtension;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Reference;
use Survos\Kit\AbstractSurvosBundle;

// Symfony\Component\HttpKernel\Bundle\Bundle <-- Flex auto-registration marker (see Survos\Kit\AbstractSurvosBundle)
final class SurvosFlickrBundle extends AbstractSurvosBundle
{
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        parent::loadExtension($config, $container, $builder);

        $builder->autowire(FlickrService::class)
            ->setAutowired(true)
            ->setAutoconfigured(true)
            ->setArgument('$apiKey', $config['api_key'])
            ->setArgument('$secret', $config['secret'])
            ->setArgument('$cacheExpiration', $config['cache_expiration'])
            ->setArgument('$accessToken', $config['access_token'])
            ->setArgument('$accessTokenSecret', $config['access_token_secret'])

            ->setArgument(
                '$security',
                new Reference('security.helper', ContainerInterface::NULL_ON_INVALID_REFERENCE)
            );

        $builder
            ->autowire('survos.flickr_twig', TwigExtension::class)
            ->addTag('twig.extension')
        ;

    }

    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
            ->scalarNode('api_key')->defaultValue('')->end()
            ->scalarNode('secret')->defaultValue('')->end()
            ->integerNode('cache_expiration')->min(0)->defaultValue(3600)->end()
            ->scalarNode('access_token')->defaultNull()->end()
            ->scalarNode('access_token_secret')->defaultNull()->end()
            ->end();
    }
}
