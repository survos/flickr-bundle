<?php

declare(strict_types=1);

namespace Survos\FlickrBundle\Util;

/**
 * Flickr static image URLs, without the API: a photo's server, id and secret are enough to build
 * any of the standard sizes (https://www.flickr.com/services/api/misc.urls.html).
 *
 *   FlickrUrl::parse('http://farm4.staticflickr.com/3735/11111459776_05b952ca0e_b.jpg')
 *     => ['server' => '3735', 'id' => '11111459776', 'secret' => '05b952ca0e', 'size' => 'b', 'format' => 'jpg']
 *   FlickrUrl::image('3735', '11111459776', '05b952ca0e', 'q')
 *     => 'https://live.staticflickr.com/3735/11111459776_05b952ca0e_q.jpg'
 *
 * Sizes with the photo's regular secret: s 75 sq, q 150 sq, t 100, m 240, n 320, w 400, '' 500,
 * z 640, c 800, b 1024. The original (o) and h/k and above use their own secrets, which a static URL
 * of another size does not reveal, so image() refuses them.
 */
final class FlickrUrl
{
    public const SIZES = ['s', 'q', 't', 'm', 'n', 'w', '', 'z', 'c', 'b'];

    private const STATIC = '#^https?://(?:farm\d+\.|live\.|c\d+\.)?static\.?flickr\.com/(?<server>\d+)/(?<id>\d+)_(?<secret>[0-9a-f]+)(?:_(?<size>[a-z0-9]+))?\.(?<format>jpg|jpeg|png|gif)$#i';

    /** @return array{server:string,id:string,secret:string,size:string,format:string}|null */
    public static function parse(string $url): ?array
    {
        if (preg_match(self::STATIC, trim($url), $m) !== 1) {
            return null;
        }

        return ['server' => $m['server'], 'id' => $m['id'], 'secret' => $m['secret'], 'size' => $m['size'] ?? '', 'format' => strtolower($m['format'])];
    }

    public static function image(string|int $server, string|int $id, string $secret, string $size = 'm', string $format = 'jpg'): ?string
    {
        if (!in_array($size, self::SIZES, true) || $server === '' || $id === '' || $secret === '') {
            return null;
        }

        return sprintf('https://live.staticflickr.com/%s/%s_%s%s.%s', $server, $id, $secret, $size === '' ? '' : '_' . $size, $format);
    }

    /** Another size of the photo a static URL shows, or null when the URL is not a Flickr static URL. */
    public static function resize(string $url, string $size): ?string
    {
        $p = self::parse($url);

        return $p === null ? null : self::image($p['server'], $p['id'], $p['secret'], $size, $p['format']);
    }

    public static function page(string|int $id, ?string $owner = null): string
    {
        return $owner !== null && $owner !== ''
            ? sprintf('https://www.flickr.com/photos/%s/%s', $owner, $id)
            : sprintf('https://www.flickr.com/photo.gne?id=%s', $id);
    }
}
