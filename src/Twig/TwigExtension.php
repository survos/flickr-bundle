<?php

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
            new TwigFunction('flickrAlbumUrl', [$this, 'flickrAlbumUrl']),
            new TwigFunction('flickrPageUrl', [$this, 'flickrPageUrl']),
            new TwigFunction('flickrThumbnailUrl',
                [$this, 'flickrThumbnailUrl']),
            //            new TwigFunction('function_name', [::class, 'doSomething']),
        ];
    }

    public function flickrPageUrl(array|object|int|string $record)
    {
        if (is_object($record)) {
            $record = (array)$record;
        }
        return sprintf('https://www.flickr.com/photo.gne?id=%s', is_array($record) ? $record['id']: $record);
    }
    public function flickrAlbumUrl(array $album)
    {
        return sprintf('https://www.flickr.com/photos/%s/albums/%s', $album['username'], $album['id']);
    }
    public function flickrThumbnailUrl(array|object $record, string $size='m', string $format = 'jpg')
    {
        if (is_object($record)) {
            $record = (array)$record;
        }

//        https://live.staticflickr.com/{server-id}/{id}_{o-secret}_o.{o-format}
//        https://www.flickr.com/services/api/misc.urls.html
//        You can also use s,q,t for cropped squares,
// for small, z for medium, and b for large.
        return sprintf('https://live.staticflickr.com/%s/%s_%s_%s.%s',
            $record['server'],
            $record['id'],
            $record['secret'],
            $size,
            $format
        );
    }


}
