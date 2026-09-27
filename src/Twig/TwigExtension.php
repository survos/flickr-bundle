<?php

declare(strict_types=1);

namespace Survos\FlickrBundle\Twig;

use Survos\FlickrBundle\Services\FlickrService;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

class TwigExtension extends AbstractExtension
{
    public function __construct(private ?FlickrService $flickrService=null)
    {
    }

    public function getFilters(): array
    {
        return [
            // If your filter generates SAFE HTML, add ['is_safe' => ['html']]
            // Reference: https://twig.symfony.com/doc/3.x/advanced.html#automatic-escaping
            new TwigFilter('filter_name', fn (string $s) => '@todo: filter '.$s),
        ];
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('flickrAlbumUrl', [$this->flickrService, 'flickrAlbumUrl']),
            new TwigFunction('flickrPageUrl', [$this->flickrService, 'flickrPageUrl']),
            // another size of a Flickr static URL: flickrResize(url, 'q') -> the 150 px square
            new TwigFunction('flickrResize', [\Survos\FlickrBundle\Util\FlickrUrl::class, 'resize']),
            new TwigFunction('flickrThumbnailUrl',
                [$this->flickrService, 'flickrThumbnailUrl']),
            //            new TwigFunction('function_name', [::class, 'doSomething']),
        ];
    }



}
